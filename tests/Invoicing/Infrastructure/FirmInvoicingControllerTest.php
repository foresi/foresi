<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Tests\Invoicing\Infrastructure;

use App\Identity\Domain\Role;
use App\Invoicing\Application\FirmInvoicingSettings;
use App\Invoicing\Application\NumberCounter;
use App\Invoicing\Application\SeriesCatalog;
use App\Invoicing\Domain\DocumentKind;
use App\Invoicing\Domain\FirmInvoicing;
use App\Invoicing\Domain\Series;
use App\Invoicing\Domain\SeriesFormat;
use App\Invoicing\Domain\TotalRounding;
use App\Party\Domain\Address;
use App\Party\Domain\LegalEntity;
use App\Party\Domain\RegistrationNumber;
use App\Party\Domain\VatStatus;
use App\Tenancy\Application\TenantContext;
use App\Tenancy\Domain\TenantId;
use App\Tests\Identity\Support\SignedInBrowser;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Clock\Clock;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Clock\NativeClock;

final class FirmInvoicingControllerTest extends WebTestCase
{
    private KernelBrowser $client;
    private SignedInBrowser $browser;
    private TenantContext $context;
    private TenantId $tenant;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->client->disableReboot();
        $this->context = self::getContainer()->get(TenantContext::class);
        $this->browser = new SignedInBrowser($this->client, self::getContainer()->get('doctrine.orm.entity_manager'), $this->context);
        Clock::set(new MockClock('2026-10-31 12:00:00'));
    }

    protected function tearDown(): void
    {
        Clock::set(new NativeClock());
        parent::tearDown();
    }

    public function testAFirmStartsWithTheStandardSettings(): void
    {
        $firm = $this->signedInFirm();

        $this->client->request('GET', $this->settingsUrl($firm));

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Invoicing');
        self::assertSelectorExists('main [data-action-bar]');
        self::assertSame('whole_unit', $this->client->getCrawler()->filter('select[name="rounding"] option[selected]')->attr('value'));
    }

    public function testTheOwnerSavesTheSettings(): void
    {
        $firm = $this->signedInFirm();

        $this->saveSettings($firm, [
            'rounding' => 'none',
            'constantSymbol' => '0308',
            'textBefore' => 'Thank you for your order.',
            'textAfter' => 'Payable by transfer.',
            'contactEmail' => 'invoices@example.com',
            'contactPhone' => '+420 123 456 789',
            'contactWeb' => 'example.com',
        ]);

        self::assertResponseRedirects($this->settingsUrl($firm));
        $this->client->followRedirect();
        self::assertSelectorTextContains('main', 'The invoicing settings are saved.');

        $stored = $this->storedSettings($firm);
        self::assertSame(TotalRounding::None, $stored->rounding());
        self::assertSame('0308', $stored->constantSymbol());
        self::assertSame('Thank you for your order.', $stored->textBefore());
        self::assertSame('Payable by transfer.', $stored->textAfter());
        self::assertSame('invoices@example.com', $stored->contactEmail());
        self::assertSame('+420 123 456 789', $stored->contactPhone());
        self::assertSame('example.com', $stored->contactWeb());
    }

    public function testSavingTwiceKeepsOneRow(): void
    {
        $firm = $this->signedInFirm();

        $this->saveSettings($firm, ['constantSymbol' => '0308']);
        $this->saveSettings($firm, ['constantSymbol' => '0008']);

        self::assertSame('0008', $this->storedSettings($firm)->constantSymbol());
        self::assertCount(1, $this->em()->getConnection()->fetchFirstColumn('SELECT id FROM firm_invoicing'));
    }

    public function testASavedChangeIsAudited(): void
    {
        $firm = $this->signedInFirm();

        $this->saveSettings($firm, ['constantSymbol' => '0308']);

        self::assertNotSame([], $this->em()->getConnection()->fetchFirstColumn("SELECT id FROM audit_entry WHERE entity_class LIKE '%FirmInvoicing'"));
    }

    /**
     * @param array<string, string> $values
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('wrongSettings')]
    public function testWrongSettingsGiveAFormError(array $values, string $field): void
    {
        $firm = $this->signedInFirm();

        $this->saveSettings($firm, $values);

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorExists('#'.$field.'-error');
        self::assertSame([], $this->em()->getConnection()->fetchFirstColumn('SELECT id FROM firm_invoicing'));
    }

    /**
     * @return iterable<string, array{array<string, string>, string}>
     */
    public static function wrongSettings(): iterable
    {
        yield 'unknown rounding' => [['rounding' => 'half'], 'rounding'];
        yield 'letters in the symbol' => [['constantSymbol' => '03A8'], 'constantSymbol'];
        yield 'a long symbol' => [['constantSymbol' => '12345678901'], 'constantSymbol'];
        yield 'not an e-mail' => [['contactEmail' => 'nobody'], 'contactEmail'];
        yield 'a long text' => [['textBefore' => str_repeat('a', 2001)], 'textBefore'];
        yield 'a long closing text' => [['textAfter' => str_repeat('a', 2001)], 'textAfter'];
        yield 'a long phone' => [['contactPhone' => str_repeat('1', 33)], 'contactPhone'];
        yield 'a long web' => [['contactWeb' => str_repeat('a', 256)], 'contactWeb'];
    }

    public function testAMemberGets403(): void
    {
        $tenant = $this->browser->seed(['Tenant A'], role: Role::Member)[0];
        $firm = $this->firm($tenant);
        $this->browser->signIn();

        foreach (['/invoicing', '/invoicing/series/new'] as $path) {
            $this->client->request('GET', '/settings/firms/'.$firm->id().$path);
            self::assertResponseStatusCodeSame(403);
        }
    }

    public function testAFirmOfAnotherTenantGets404(): void
    {
        $this->signedInFirm();
        $foreign = $this->firm($this->browser->fixtures->tenant('Foreign Tenant'));

        foreach (['/invoicing', '/invoicing/series/new'] as $path) {
            $this->client->request('GET', '/settings/firms/'.$foreign->id().$path);
            self::assertResponseStatusCodeSame(404);
        }
    }

    public function testASeriesOfAnotherFirmGets404(): void
    {
        $firm = $this->signedInFirm();
        $other = $this->firm($this->tenant, '12345687');
        $series = $this->series($other);

        $this->client->request('GET', $this->editUrl($firm, $series));

        self::assertResponseStatusCodeSame(404);
    }

    public function testACsrfFailureChangesNothing(): void
    {
        $firm = $this->signedInFirm();

        $this->client->request('POST', $this->settingsUrl($firm), ['_token' => 'wrong', 'rounding' => 'none']);

        self::assertResponseStatusCodeSame(403);
    }

    public function testTheFirmFormLinksToTheSettings(): void
    {
        $firm = $this->signedInFirm();

        $this->client->request('GET', '/settings/firms/'.$firm->id().'/edit');

        self::assertSelectorExists('a[href="'.$this->settingsUrl($firm).'"]');
    }

    public function testTheScreensAreTranslated(): void
    {
        [$this->tenant] = $this->browser->seed(['Tenant A'], 'cs');
        $firm = $this->firm($this->tenant);
        $this->browser->signIn();

        $this->client->request('GET', $this->settingsUrl($firm));
        self::assertSelectorTextContains('h1', 'Fakturace');

        $this->client->request('GET', $this->settingsUrl($firm).'/series/new');
        self::assertSelectorTextContains('h1', 'Nová číselná řada');
    }

    public function testASeriesSavesAndTheFirstBecomesTheDefault(): void
    {
        $firm = $this->signedInFirm();

        $this->saveNewSeries($firm, ['name' => 'Standard', 'format' => 'FV{YY}-{NNN}']);

        self::assertResponseRedirects($this->settingsUrl($firm));
        $this->client->followRedirect();
        self::assertSelectorTextContains('main', 'The series is saved.');
        self::assertSelectorTextContains('main table', 'FV{YY}-{NNN}');
        self::assertSelectorTextContains('main table', 'FV26-001');

        $series = $this->onlySeries($firm);
        self::assertSame('Standard', $series->name());
        self::assertTrue($series->isDefault());
    }

    public function testTheSameSubmissionTwiceSavesOnce(): void
    {
        $firm = $this->signedInFirm();
        $form = $this->newSeriesForm($firm);
        $values = ['name' => 'Standard', 'format' => '{YYYY}{NNNN}'] + $form;

        $this->client->request('POST', $this->settingsUrl($firm).'/series/new', $values);
        $this->client->request('POST', $this->settingsUrl($firm).'/series/new', $values);

        self::assertResponseRedirects($this->settingsUrl($firm));
        self::assertCount(1, $this->allSeries($firm));
    }

    public function testAWrongTokenGivesAFormError(): void
    {
        $firm = $this->signedInFirm();

        $this->saveNewSeries($firm, ['name' => 'Standard', 'format' => '{YYYY}{Q}{NNNN}']);

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('#format-error', 'Use only');
        self::assertSame([], $this->allSeries($firm));
    }

    public function testANameIsRequiredAndUnique(): void
    {
        $firm = $this->signedInFirm();
        $this->series($firm);

        $this->saveNewSeries($firm, ['name' => '', 'format' => 'Z{NNNN}']);
        self::assertResponseStatusCodeSame(422);
        self::assertSelectorExists('#name-error');

        $this->saveNewSeries($firm, ['name' => 'Standard', 'format' => 'Z{NNNN}']);
        self::assertResponseStatusCodeSame(422);
        self::assertSelectorExists('#name-error');

        $this->saveNewSeries($firm, ['name' => str_repeat('a', 101), 'format' => 'Z{NNNN}']);
        self::assertResponseStatusCodeSame(422);
        self::assertSelectorExists('#name-error');
    }

    public function testAFormatWhoseNumbersCollideIsRefused(): void
    {
        $firm = $this->signedInFirm();
        $this->series($firm, 'FV{YY}{NNN}');

        $this->saveNewSeries($firm, ['name' => 'Second', 'format' => 'FV-{YY}/{NNN}']);

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorExists('#format-error');
    }

    public function testASeriesCanBeEdited(): void
    {
        $firm = $this->signedInFirm();
        $series = $this->series($firm);

        $this->saveSeries($firm, $series, ['name' => 'Renamed', 'format' => 'Z{YYYY}{NNNN}']);

        self::assertResponseRedirects($this->settingsUrl($firm));
        $stored = $this->onlySeries($firm);
        self::assertSame('Renamed', $stored->name());
        self::assertSame('Z{YYYY}{NNNN}', $stored->format()->toString());
    }

    public function testTheFormatIsLockedOnceTheSeriesHasDocuments(): void
    {
        $firm = $this->signedInFirm();
        $series = $this->series($firm);
        $this->issue($series);

        $crawler = $this->client->request('GET', $this->editUrl($firm, $series));
        self::assertNotNull($crawler->filter('#format')->attr('disabled'));

        // The browser sends no disabled field, and the rest of the series still saves.
        $this->saveSeries($firm, $series, ['name' => 'Renamed', 'format' => '']);
        self::assertResponseRedirects($this->settingsUrl($firm));

        $this->saveSeries($firm, $series, ['name' => 'Renamed', 'format' => 'Z{NNNN}']);
        self::assertResponseStatusCodeSame(422);
        self::assertSelectorExists('#format-error');
        self::assertSame(Series::STANDARD_FORMAT, $this->onlySeries($firm)->format()->toString());
    }

    public function testTheNextNumberMovesTheSeriesForward(): void
    {
        $firm = $this->signedInFirm();
        $series = $this->series($firm);

        $this->saveSeries($firm, $series, ['name' => 'Standard', 'format' => Series::STANDARD_FORMAT, 'nextNumber' => '90']);

        self::assertResponseRedirects($this->settingsUrl($firm));
        self::assertSame(90, $this->onlySeries($firm)->startFor('2026'));
        $this->client->followRedirect();
        self::assertSelectorTextContains('main table', '20260090');
    }

    public function testANewSeriesCanStartAboveOne(): void
    {
        $firm = $this->signedInFirm();

        $this->saveNewSeries($firm, ['name' => 'Standard', 'format' => '{NNNN}', 'nextNumber' => '5']);

        self::assertSame(5, $this->onlySeries($firm)->startFor('all'));
    }

    public function testANextNumberBelowTheCounterGetsAFormError(): void
    {
        $firm = $this->signedInFirm();
        $series = $this->series($firm);
        $this->issue($series);
        $this->issue($series);

        $this->saveSeries($firm, $series, ['name' => 'Standard', 'format' => '', 'nextNumber' => '2']);

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('#nextNumber-error', '3');
        self::assertSame(1, $this->onlySeries($firm)->startFor('2026'));
    }

    public function testANextNumberAboveTheCounterIsFine(): void
    {
        $firm = $this->signedInFirm();
        $series = $this->series($firm);
        $this->issue($series);

        $this->saveSeries($firm, $series, ['name' => 'Standard', 'format' => '', 'nextNumber' => '2']);

        self::assertResponseRedirects($this->settingsUrl($firm));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function wrongNumbers(): iterable
    {
        yield 'zero' => ['0'];
        yield 'negative' => ['-1'];
        yield 'text' => ['abc'];
        yield 'too big for the counter' => ['2147483648'];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('wrongNumbers')]
    public function testAWrongNextNumberGetsAFormError(string $number): void
    {
        $firm = $this->signedInFirm();
        $series = $this->series($firm);

        $this->saveSeries($firm, $series, ['name' => 'Standard', 'format' => Series::STANDARD_FORMAT, 'nextNumber' => $number]);

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorExists('#nextNumber-error');
    }

    public function testThereIsOneDefaultPerFirm(): void
    {
        $firm = $this->signedInFirm();
        $this->saveNewSeries($firm, ['name' => 'First', 'format' => '{YYYY}{NNNN}']);
        $this->saveNewSeries($firm, ['name' => 'Second', 'format' => 'Z{YYYY}{NNNN}', 'default' => '1']);

        $defaults = array_filter($this->allSeries($firm), static fn (Series $series): bool => $series->isDefault());

        self::assertCount(1, $defaults);
        self::assertSame('Second', array_values($defaults)[0]->name());
    }

    public function testASecondSeriesIsNoDefaultByItself(): void
    {
        $firm = $this->signedInFirm();
        $this->saveNewSeries($firm, ['name' => 'First', 'format' => '{YYYY}{NNNN}']);
        $this->saveNewSeries($firm, ['name' => 'Second', 'format' => 'Z{YYYY}{NNNN}']);

        $defaults = array_filter($this->allSeries($firm), static fn (Series $series): bool => $series->isDefault());

        self::assertSame('First', array_values($defaults)[0]->name());
    }

    public function testTheDefaultStaysWhenItsBoxIsNotSent(): void
    {
        $firm = $this->signedInFirm();
        $this->saveNewSeries($firm, ['name' => 'First', 'format' => '{YYYY}{NNNN}']);
        $series = $this->onlySeries($firm);

        $this->saveSeries($firm, $series, ['name' => 'First', 'format' => '{YYYY}{NNNN}']);

        self::assertTrue($this->onlySeries($firm)->isDefault());
    }

    public function testAFirmWithoutSeriesIsToldWhatItGets(): void
    {
        $firm = $this->signedInFirm();

        $this->client->request('GET', $this->settingsUrl($firm));

        self::assertSelectorNotExists('main table');
        self::assertSelectorTextContains('main', '20260001');
        self::assertSelectorExists('main a[href="'.$this->settingsUrl($firm).'/series/new"]');
    }

    private function signedInFirm(): LegalEntity
    {
        [$this->tenant] = $this->browser->seed(['Tenant A']);
        $this->browser->signIn();

        return $this->firm($this->tenant);
    }

    private function firm(TenantId $tenant, string $number = '12345695'): LegalEntity
    {
        $this->context->switchTo($tenant);
        $firm = new LegalEntity('Example Firm s.r.o.', new RegistrationNumber('CZ', $number), null, new Address('Example Street 1', 'Example City', '100 00', 'CZ'), VatStatus::NotRegistered, '', []);
        $this->em()->persist($firm);
        $this->em()->flush();
        $this->context->reset();

        return $firm;
    }

    private function series(LegalEntity $firm, string $format = Series::STANDARD_FORMAT): Series
    {
        $this->context->switchTo($firm->tenantId());
        $stored = $this->em()->getRepository(LegalEntity::class)->find($firm->id()) ?? throw new \LogicException('The firm is missing.');
        $series = new Series($stored, 'Standard', SeriesFormat::fromString($format), DocumentKind::Invoice);
        $this->em()->persist($series);
        $this->em()->flush();
        $this->context->reset();

        return $series;
    }

    private function issue(Series $series): void
    {
        $this->context->switchTo($series->tenantId());
        self::getContainer()->get(NumberCounter::class)->next($series, new \DateTimeImmutable('2026-10-31'));
        $this->context->reset();
    }

    /**
     * @param array<string, string> $values
     */
    private function saveSettings(LegalEntity $firm, array $values): void
    {
        $crawler = $this->client->request('GET', $this->settingsUrl($firm));
        $form = $crawler->filter('main form')->last()->form();
        $this->client->request('POST', $this->settingsUrl($firm), $values + $form->getPhpValues());
    }

    /**
     * @return array<array-key, mixed>
     */
    private function newSeriesForm(LegalEntity $firm): array
    {
        $crawler = $this->client->request('GET', $this->settingsUrl($firm).'/series/new');

        return $crawler->filter('main form')->form()->getPhpValues();
    }

    /**
     * @param array<string, string> $values
     */
    private function saveNewSeries(LegalEntity $firm, array $values): void
    {
        $this->client->request('POST', $this->settingsUrl($firm).'/series/new', $values + $this->newSeriesForm($firm));
    }

    /**
     * @param array<string, string> $values
     */
    private function saveSeries(LegalEntity $firm, Series $series, array $values): void
    {
        $crawler = $this->client->request('GET', $this->editUrl($firm, $series));
        $form = $crawler->filter('main form')->form();
        $this->client->request('POST', $this->editUrl($firm, $series), $values + $form->getPhpValues());
    }

    private function settingsUrl(LegalEntity $firm): string
    {
        return '/settings/firms/'.$firm->id().'/invoicing';
    }

    private function editUrl(LegalEntity $firm, Series $series): string
    {
        return $this->settingsUrl($firm).'/series/'.$series->id().'/edit';
    }

    private function storedSettings(LegalEntity $firm): FirmInvoicing
    {
        $this->em()->clear();
        $this->context->switchTo($firm->tenantId());
        $stored = self::getContainer()->get(FirmInvoicingSettings::class)->for($this->em()->getRepository(LegalEntity::class)->find($firm->id()) ?? throw new \LogicException('The firm is missing.'));
        $this->context->reset();

        return $stored;
    }

    /**
     * @return list<Series>
     */
    private function allSeries(LegalEntity $firm): array
    {
        $this->em()->clear();
        $this->context->switchTo($firm->tenantId());
        $series = self::getContainer()->get(SeriesCatalog::class)->forFirm($firm);
        $this->context->reset();

        return $series;
    }

    private function onlySeries(LegalEntity $firm): Series
    {
        $series = $this->allSeries($firm);
        self::assertCount(1, $series);

        return $series[0];
    }

    private function em(): EntityManagerInterface
    {
        return self::getContainer()->get('doctrine.orm.entity_manager');
    }
}
