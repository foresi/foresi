<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\Invoicing\Infrastructure;

use Doctrine\ORM\EntityManagerInterface;
use Foresi\Identity\Domain\Role;
use Foresi\Invoicing\Application\FirmInvoicingSettings;
use Foresi\Invoicing\Application\NumberCounter;
use Foresi\Invoicing\Application\SeriesCatalog;
use Foresi\Invoicing\Application\View\SeriesLists;
use Foresi\Invoicing\Contract\DocumentKind;
use Foresi\Invoicing\Contract\TotalRounding;
use Foresi\Invoicing\Domain\FirmInvoicing;
use Foresi\Invoicing\Domain\Series;
use Foresi\Invoicing\Domain\SeriesFormat;
use Foresi\Party\Domain\Address;
use Foresi\Party\Domain\LegalEntity;
use Foresi\Party\Domain\RegistrationNumber;
use Foresi\Party\Domain\VatStatus;
use Foresi\Tests\Identity\Support\SignedInBrowser;
use Foresi\Tests\Ui\Support\ListPageAssertions;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Clock\Clock;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Clock\NativeClock;
use Symfony\Component\DomCrawler\Crawler;

final class FirmInvoicingControllerTest extends WebTestCase
{
    use ListPageAssertions;

    private KernelBrowser $client;
    private SignedInBrowser $browser;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->client->disableReboot();
        $this->browser = new SignedInBrowser($this->client, self::getContainer()->get('doctrine.orm.entity_manager'));
        Clock::set(new MockClock('2026-10-31 12:00:00'));
    }

    protected function tearDown(): void
    {
        Clock::set(new NativeClock());
        parent::tearDown();
    }

    public function testAFirmStartsWithTheStandardSettings(): void
    {
        $this->signedInFirm();

        $this->client->request('GET', $this->settingsUrl());

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Invoicing');
        self::assertSelectorExists('main [data-action-bar]');
        self::assertSame('whole_unit', $this->client->getCrawler()->filter('select[name="rounding"] option[selected]')->attr('value'));
    }

    public function testTheSeriesAreAListWithViewsAboveTheForm(): void
    {
        $this->signedInFirm();
        $this->series();
        $this->em()->persist(new Series('Advances', SeriesFormat::fromString('ZF{YYYY}{NNNN}'), DocumentKind::Advance));
        $this->em()->flush();
        self::assertListWithViews(self::getContainer()->get(SeriesLists::class), 'invoicing.series', ['name', 'kind', 'default'], ['all' => false]);

        // The columns the page always showed; the person's own stay, and Reset forgets them.
        $crawler = $this->client->request('GET', $this->settingsUrl());
        self::assertSame(['name', 'kind', 'format', 'next', 'default'], $this->seriesColumns($crawler));
        $crawler = $this->client->request('GET', $this->settingsUrl().'?cols=name,format');
        self::assertSame(['name', 'format'], $this->seriesColumns($crawler));
        self::assertListTable($crawler, '[data-series]', ['widths' => '']);

        // The kind of documents is a field to filter on, and the builder's rows become the filter.
        self::assertSame(['Advances'], $this->seriesNames(self::applyBuilder($this->client, $this->settingsUrl(), 'name', 'Adv')));
        self::assertSame(['Standard'], $this->seriesNames($this->client->request('GET', $this->settingsUrl().'?q=stand')));
        // The form of the settings stays under the list.
        self::assertCount(1, $this->client->getCrawler()->filter('select[name="rounding"]'));
    }

    public function testTheOwnerSavesTheSettings(): void
    {
        $this->signedInFirm();

        $this->saveSettings([
            'rounding' => 'none',
            'constantSymbol' => '0308',
            'textBefore' => 'Thank you for your order.',
            'textAfter' => 'Payable by transfer.',
            'contactEmail' => 'invoices@example.com',
            'contactPhone' => '+420 123 456 789',
            'contactWeb' => 'example.com',
        ]);

        self::assertResponseRedirects($this->settingsUrl());
        $this->client->followRedirect();
        self::assertSelectorTextContains('main', 'The invoicing settings are saved.');

        $stored = $this->storedSettings();
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
        $this->signedInFirm();

        $this->saveSettings(['constantSymbol' => '0308']);
        $this->saveSettings(['constantSymbol' => '0008']);

        self::assertSame('0008', $this->storedSettings()->constantSymbol());
        self::assertCount(1, $this->em()->getConnection()->fetchFirstColumn('SELECT id FROM firm_invoicing'));
    }

    public function testASavedChangeIsAudited(): void
    {
        $this->signedInFirm();

        $this->saveSettings(['constantSymbol' => '0308']);

        self::assertNotSame([], $this->em()->getConnection()->fetchFirstColumn("SELECT id FROM audit_entry WHERE entity_class LIKE '%FirmInvoicing'"));
    }

    /**
     * @param array<string, string> $values
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('wrongSettings')]
    public function testWrongSettingsGiveAFormError(array $values, string $field): void
    {
        $this->signedInFirm();

        $this->saveSettings($values);

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
        $this->browser->seed(role: Role::Member);
        $this->firm();
        $this->browser->signIn();

        foreach (['/invoicing', '/invoicing/series/new'] as $path) {
            $this->client->request('GET', '/settings/firm'.$path);
            self::assertResponseStatusCodeSame(403);
        }
    }

    public function testACsrfFailureChangesNothing(): void
    {
        $this->signedInFirm();

        $this->client->request('POST', $this->settingsUrl(), ['_token' => 'wrong', 'rounding' => 'none']);

        self::assertResponseStatusCodeSame(403);
    }

    public function testTheFirmFormLinksToTheSettings(): void
    {
        $this->signedInFirm();

        $this->client->request('GET', '/settings/firm');

        self::assertSelectorExists('a[href="'.$this->settingsUrl().'"]');
    }

    public function testTheScreensAreTranslated(): void
    {
        $this->browser->seed('cs');
        $this->firm();
        $this->browser->signIn();

        $this->client->request('GET', $this->settingsUrl());
        self::assertSelectorTextContains('h1', 'Fakturace');

        $this->client->request('GET', $this->settingsUrl().'/series/new');
        self::assertSelectorTextContains('h1', 'Nová číselná řada');
    }

    public function testASeriesSavesAndTheFirstBecomesTheDefault(): void
    {
        $this->signedInFirm();

        $this->saveNewSeries(['name' => 'Standard', 'format' => 'FV{YY}-{NNN}']);

        self::assertResponseRedirects($this->settingsUrl());
        $this->client->followRedirect();
        self::assertSelectorTextContains('main', 'The series is saved.');
        self::assertSelectorTextContains('main table', 'FV{YY}-{NNN}');
        self::assertSelectorTextContains('main table', 'FV26-001');

        $series = $this->onlySeries();
        self::assertSame('Standard', $series->name());
        self::assertTrue($series->isDefault());
    }

    public function testTheSameSubmissionTwiceSavesOnce(): void
    {
        $this->signedInFirm();
        $form = $this->newSeriesForm();
        $values = ['name' => 'Standard', 'format' => '{YYYY}{NNNN}'] + $form;

        $this->client->request('POST', $this->settingsUrl().'/series/new', $values);
        $this->client->request('POST', $this->settingsUrl().'/series/new', $values);

        self::assertResponseRedirects($this->settingsUrl());
        self::assertCount(1, $this->allSeries());
    }

    public function testAWrongTokenGivesAFormError(): void
    {
        $this->signedInFirm();

        $this->saveNewSeries(['name' => 'Standard', 'format' => '{YYYY}{Q}{NNNN}']);

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('#format-error', 'Use only');
        self::assertSame([], $this->allSeries());
    }

    public function testANameIsRequiredAndUnique(): void
    {
        $this->signedInFirm();
        $this->series();

        $this->saveNewSeries(['name' => '', 'format' => 'Z{NNNN}']);
        self::assertResponseStatusCodeSame(422);
        self::assertSelectorExists('#name-error');

        $this->saveNewSeries(['name' => 'Standard', 'format' => 'Z{NNNN}']);
        self::assertResponseStatusCodeSame(422);
        self::assertSelectorExists('#name-error');

        $this->saveNewSeries(['name' => str_repeat('a', 101), 'format' => 'Z{NNNN}']);
        self::assertResponseStatusCodeSame(422);
        self::assertSelectorExists('#name-error');
    }

    public function testAFormatWhoseNumbersCollideIsRefused(): void
    {
        $this->signedInFirm();
        $this->series('FV{YY}{NNN}');

        $this->saveNewSeries(['name' => 'Second', 'format' => 'FV-{YY}/{NNN}']);

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorExists('#format-error');
    }

    public function testAFormatDifferingOnlyInTheSequenceWidthIsRefused(): void
    {
        $this->signedInFirm();
        $this->series('{YYYY}{NNNN}');

        $this->saveNewSeries(['name' => 'Second', 'format' => '{YYYY}{NNN}']);

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorExists('#format-error');
    }

    public function testAnArchivedSeriesKeepsItsFormatAndNameTaken(): void
    {
        $this->signedInFirm();
        $this->archive($this->series('FV{YY}{NNN}'));

        $this->saveNewSeries(['name' => 'Second', 'format' => 'FV{YY}{NNN}']);
        self::assertResponseStatusCodeSame(422);
        self::assertSelectorExists('#format-error');

        $this->saveNewSeries(['name' => 'Standard', 'format' => 'Z{YY}{NNN}']);
        self::assertResponseStatusCodeSame(422);
        self::assertSelectorExists('#name-error');
    }

    public function testASeriesCanBeEdited(): void
    {
        $this->signedInFirm();
        $series = $this->series();

        $this->saveSeries($series, ['name' => 'Renamed', 'format' => 'Z{YYYY}{NNNN}']);

        self::assertResponseRedirects($this->settingsUrl());
        $stored = $this->onlySeries();
        self::assertSame('Renamed', $stored->name());
        self::assertSame('Z{YYYY}{NNNN}', $stored->format()->toString());
    }

    public function testTheFormatIsLockedOnceTheSeriesHasDocuments(): void
    {
        $this->signedInFirm();
        $series = $this->series();
        $this->issue($series);

        $crawler = $this->client->request('GET', $this->editUrl($series));
        self::assertNotNull($crawler->filter('#format')->attr('disabled'));

        // The browser sends no disabled field, and the rest of the series still saves.
        $this->saveSeries($series, ['name' => 'Renamed', 'format' => '']);
        self::assertResponseRedirects($this->settingsUrl());

        $this->saveSeries($series, ['name' => 'Renamed', 'format' => 'Z{NNNN}']);
        self::assertResponseStatusCodeSame(422);
        self::assertSelectorExists('#format-error');
        self::assertSame(Series::STANDARD_FORMAT, $this->onlySeries()->format()->toString());
    }

    public function testTheNextNumberMovesTheSeriesForward(): void
    {
        $this->signedInFirm();
        $series = $this->series();

        $this->saveSeries($series, ['name' => 'Standard', 'format' => Series::STANDARD_FORMAT, 'nextNumber' => '90']);

        self::assertResponseRedirects($this->settingsUrl());
        self::assertSame(90, $this->onlySeries()->startFor('2026'));
        $this->client->followRedirect();
        self::assertSelectorTextContains('main table', '20260090');
    }

    public function testTheYearDefaultsToThisYearAndTheNumberStaysEmpty(): void
    {
        $this->signedInFirm();
        $series = $this->series();
        $this->issue($series);

        $crawler = $this->client->request('GET', $this->editUrl($series));

        self::assertSame('2026', $crawler->filter('#startYear')->attr('value'));
        self::assertSame('', $crawler->filter('#nextNumber')->attr('value'));
        self::assertSame('2', $crawler->filter('#nextNumber')->attr('placeholder'));
    }

    public function testANextYearCanBeSeededExplicitly(): void
    {
        $this->signedInFirm();
        $series = $this->series();
        $this->issue($series);

        $this->saveSeries($series, ['name' => 'Standard', 'format' => '', 'nextNumber' => '500', 'startYear' => '2027']);

        self::assertResponseRedirects($this->settingsUrl());
        $stored = $this->onlySeries();
        self::assertSame(500, $stored->startFor('2027'));
        self::assertSame(1, $stored->startFor('2026'));
    }

    public function testSavingWithAnotherYearAndNoNumberSetsNothing(): void
    {
        $this->signedInFirm();
        $series = $this->series();

        $this->saveSeries($series, ['name' => 'Standard', 'format' => Series::STANDARD_FORMAT, 'startYear' => '2027']);

        self::assertResponseRedirects($this->settingsUrl());
        self::assertSame(1, $this->onlySeries()->startFor('2027'));
    }

    public function testAFormatWithoutAYearIgnoresTheYear(): void
    {
        $this->signedInFirm();

        $this->saveNewSeries(['name' => 'Standard', 'format' => '{NNNN}', 'nextNumber' => '7', 'startYear' => '2027']);

        $stored = $this->onlySeries();
        self::assertSame(7, $stored->startFor('all'));
        self::assertSame(1, $stored->startFor('2027'));
    }

    public function testTheCounterIsCheckedInTheChosenYear(): void
    {
        $this->signedInFirm();
        $series = $this->series();
        $this->issue($series, '2025-06-01');
        $this->issue($series, '2025-06-01');

        $this->saveSeries($series, ['name' => 'Standard', 'format' => '', 'nextNumber' => '2', 'startYear' => '2025']);
        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('#nextNumber-error', '3');

        $this->saveSeries($series, ['name' => 'Standard', 'format' => '', 'nextNumber' => '2', 'startYear' => '2026']);
        self::assertResponseRedirects($this->settingsUrl());
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function wrongYears(): iterable
    {
        yield 'empty' => [''];
        yield 'short' => ['26'];
        yield 'text' => ['abcd'];
        yield 'before this century' => ['1999'];
        yield 'after it' => ['2100'];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('wrongYears')]
    public function testAWrongYearGetsAFormError(string $year): void
    {
        $this->signedInFirm();
        $series = $this->series();

        $this->saveSeries($series, ['name' => 'Standard', 'format' => Series::STANDARD_FORMAT, 'nextNumber' => '5', 'startYear' => $year]);

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorExists('#startYear-error');
    }

    public function testANewSeriesCanStartAboveOne(): void
    {
        $this->signedInFirm();

        $this->saveNewSeries(['name' => 'Standard', 'format' => '{NNNN}', 'nextNumber' => '5']);

        self::assertSame(5, $this->onlySeries()->startFor('all'));
    }

    public function testANextNumberBelowTheCounterGetsAFormError(): void
    {
        $this->signedInFirm();
        $series = $this->series();
        $this->issue($series);
        $this->issue($series);

        $this->saveSeries($series, ['name' => 'Standard', 'format' => '', 'nextNumber' => '2']);

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('#nextNumber-error', '3');
        self::assertSame(1, $this->onlySeries()->startFor('2026'));
    }

    public function testANextNumberAboveTheCounterIsFine(): void
    {
        $this->signedInFirm();
        $series = $this->series();
        $this->issue($series);

        $this->saveSeries($series, ['name' => 'Standard', 'format' => '', 'nextNumber' => '2']);

        self::assertResponseRedirects($this->settingsUrl());
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
        $this->signedInFirm();
        $series = $this->series();

        $this->saveSeries($series, ['name' => 'Standard', 'format' => Series::STANDARD_FORMAT, 'nextNumber' => $number]);

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorExists('#nextNumber-error');
    }

    public function testThereIsOneDefaultPerFirm(): void
    {
        $this->signedInFirm();
        $this->saveNewSeries(['name' => 'First', 'format' => '{YYYY}{NNNN}']);
        $this->saveNewSeries(['name' => 'Second', 'format' => 'Z{YYYY}{NNNN}', 'default' => '1']);

        $defaults = array_filter($this->allSeries(), static fn (Series $series): bool => $series->isDefault());

        self::assertCount(1, $defaults);
        self::assertSame('Second', array_values($defaults)[0]->name());
    }

    public function testASecondSeriesIsNoDefaultByItself(): void
    {
        $this->signedInFirm();
        $this->saveNewSeries(['name' => 'First', 'format' => '{YYYY}{NNNN}']);
        $this->saveNewSeries(['name' => 'Second', 'format' => 'Z{YYYY}{NNNN}']);

        $defaults = array_filter($this->allSeries(), static fn (Series $series): bool => $series->isDefault());

        self::assertSame('First', array_values($defaults)[0]->name());
    }

    public function testTheDefaultStaysWhenItsBoxIsNotSent(): void
    {
        $this->signedInFirm();
        $this->saveNewSeries(['name' => 'First', 'format' => '{YYYY}{NNNN}']);
        $series = $this->onlySeries();

        $this->saveSeries($series, ['name' => 'First', 'format' => '{YYYY}{NNNN}']);

        self::assertTrue($this->onlySeries()->isDefault());
    }

    public function testASeriesOfAChosenKindIsSavedWithIt(): void
    {
        $this->signedInFirm();

        $this->saveNewSeries(['name' => 'Advances', 'format' => 'ZF{YYYY}{NNNN}', 'kind' => 'advance']);

        self::assertResponseRedirects($this->settingsUrl());
        $this->client->followRedirect();
        self::assertSelectorTextContains('main table', 'Advance invoice');
        self::assertSame(DocumentKind::Advance, $this->onlySeries()->kind());
    }

    public function testTheNewSeriesFormStartsWithTheFormatOfTheKind(): void
    {
        $this->signedInFirm();

        $crawler = $this->client->request('GET', $this->settingsUrl().'/series/new?kind=payment_tax_document');

        self::assertSame('D8{YYYY}{NNNN}', $crawler->filter('input[name="format"]')->attr('value'));
        self::assertSame('payment_tax_document', $crawler->filter('select[name="kind"] option[selected]')->attr('value'));
    }

    public function testAnUnknownKindGetsAFormError(): void
    {
        $this->signedInFirm();

        $this->saveNewSeries(['name' => 'Odd', 'format' => 'O{YYYY}{NNNN}', 'kind' => 'receipt']);

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('main', 'Choose the kind of documents.');
        self::assertSame([], $this->allSeries());
    }

    public function testTheKindOfAStoredSeriesStays(): void
    {
        $this->signedInFirm();
        $this->saveNewSeries(['name' => 'Advances', 'format' => 'ZF{YYYY}{NNNN}', 'kind' => 'advance']);
        $series = $this->onlySeries();

        $this->saveSeries($series, ['name' => 'Renamed', 'format' => 'ZF{YYYY}{NNNN}', 'kind' => 'invoice']);

        self::assertResponseRedirects($this->settingsUrl());
        $stored = $this->onlySeries();
        self::assertSame('Renamed', $stored->name());
        self::assertSame(DocumentKind::Advance, $stored->kind());
        $crawler = $this->client->request('GET', $this->editUrl($stored));
        self::assertCount(1, $crawler->filter('select[name="kind"][disabled]'));
    }

    public function testEachKindHasADefaultOfItsOwn(): void
    {
        $this->signedInFirm();
        $this->saveNewSeries(['name' => 'Invoices', 'format' => '{YYYY}{NNNN}']);
        $this->saveNewSeries(['name' => 'Advances', 'format' => 'ZF{YYYY}{NNNN}', 'kind' => 'advance']);
        $this->saveNewSeries(['name' => 'More advances', 'format' => 'ZG{YYYY}{NNNN}', 'kind' => 'advance', 'default' => '1']);

        $defaults = array_values(array_filter($this->allSeries(), static fn (Series $series): bool => $series->isDefault()));

        self::assertSame(['Invoices', 'More advances'], array_map(static fn (Series $series): string => $series->name(), $defaults));
    }

    public function testTheStandardSeriesOfEachKindIsTranslated(): void
    {
        $this->signedInFirm();
        $this->em()->persist(Series::standard(DocumentKind::Advance));
        $this->em()->persist(Series::standard(DocumentKind::PaymentTaxDocument));
        $this->em()->flush();

        $this->client->request('GET', $this->settingsUrl());

        self::assertSelectorTextContains('main table', 'Advance invoices');
        self::assertSelectorTextContains('main table', 'Tax documents on payment');
        self::assertSelectorTextContains('main table', 'Z9{YYYY}{NNNN}');
        self::assertSelectorTextNotContains('main table', 'standard_advance');
    }

    public function testAFirmWithoutSeriesIsToldWhatItGets(): void
    {
        $this->signedInFirm();

        $this->client->request('GET', $this->settingsUrl());

        self::assertSelectorNotExists('main table');
        self::assertSelectorTextContains('main', '20260001');
        self::assertSelectorExists('main a[href="'.$this->settingsUrl().'/series/new"]');
    }

    private function signedInFirm(): void
    {
        $this->browser->seed();
        $this->browser->signIn();
        $this->firm();
    }

    /**
     * @return list<string>
     */
    private function seriesNames(Crawler $crawler): array
    {
        return $crawler->filter('[data-series] tbody tr[data-id]')->each(static fn (Crawler $row): string => trim($row->filter('th, td')->first()->text()));
    }

    /**
     * @return list<string>
     */
    private function seriesColumns(Crawler $crawler): array
    {
        return $crawler->filter('[data-series] colgroup col[data-column]')->each(static fn (Crawler $col): string => (string) $col->attr('data-column'));
    }

    private function firm(): void
    {
        $this->em()->persist(new LegalEntity('Example Firm s.r.o.', new RegistrationNumber('CZ', '12345695'), null, new Address('Example Street 1', 'Example City', '100 00', 'CZ'), VatStatus::NotRegistered, ''));
        $this->em()->flush();
    }

    private function series(string $format = Series::STANDARD_FORMAT): Series
    {
        $series = new Series('Standard', SeriesFormat::fromString($format), DocumentKind::Invoice);
        $this->em()->persist($series);
        $this->em()->flush();

        return $series;
    }

    private function issue(Series $series, string $date = '2026-10-31'): void
    {
        self::getContainer()->get(NumberCounter::class)->next($series, new \DateTimeImmutable($date));
    }

    private function archive(Series $series): void
    {
        $stored = self::getContainer()->get(SeriesCatalog::class)->get($series->id());
        $stored->archive();
        $this->em()->flush();
    }

    /**
     * @param array<string, string> $values
     */
    private function saveSettings(array $values): void
    {
        $crawler = $this->client->request('GET', $this->settingsUrl());
        $form = $crawler->filter('main form')->last()->form();
        $this->client->request('POST', $this->settingsUrl(), $values + $form->getPhpValues());
    }

    /**
     * @return array<array-key, mixed>
     */
    private function newSeriesForm(): array
    {
        $crawler = $this->client->request('GET', $this->settingsUrl().'/series/new');

        return $crawler->filter('main form')->form()->getPhpValues();
    }

    /**
     * @param array<string, string> $values
     */
    private function saveNewSeries(array $values): void
    {
        $this->client->request('POST', $this->settingsUrl().'/series/new', $values + $this->newSeriesForm());
    }

    /**
     * @param array<string, string> $values
     */
    private function saveSeries(Series $series, array $values): void
    {
        $crawler = $this->client->request('GET', $this->editUrl($series));
        $form = $crawler->filter('main form')->form();
        $this->client->request('POST', $this->editUrl($series), $values + $form->getPhpValues());
    }

    private function settingsUrl(): string
    {
        return '/settings/firm/invoicing';
    }

    private function editUrl(Series $series): string
    {
        return $this->settingsUrl().'/series/'.$series->id().'/edit';
    }

    private function storedSettings(): FirmInvoicing
    {
        $this->em()->clear();

        return self::getContainer()->get(FirmInvoicingSettings::class)->get();
    }

    /**
     * @return list<Series>
     */
    private function allSeries(): array
    {
        $this->em()->clear();

        return self::getContainer()->get(SeriesCatalog::class)->all();
    }

    private function onlySeries(): Series
    {
        $series = $this->allSeries();
        self::assertCount(1, $series);

        return $series[0];
    }

    private function em(): EntityManagerInterface
    {
        return self::getContainer()->get('doctrine.orm.entity_manager');
    }
}
