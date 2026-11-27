<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\Party\Integration\Ui;

use Doctrine\ORM\EntityManagerInterface;
use Foresi\Identity\Domain\Role;
use Foresi\Party\Application\View\FirmVatLists;
use Foresi\Party\Domain\Address;
use Foresi\Party\Domain\LegalEntity;
use Foresi\Party\Domain\RegistrationNumber;
use Foresi\Party\Domain\VatId;
use Foresi\Party\Domain\VatStatus;
use Foresi\Tests\Identity\Support\SignedInBrowser;
use Foresi\Tests\Ui\Support\ListPageAssertions;
use Foresi\Ui\Contract\Dialog\DialogRequest;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Clock\Clock;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Clock\NativeClock;
use Symfony\Component\DomCrawler\Crawler;

/**
 * The VAT tab of the firm: the statuses as a dated list, "Add a change" in the
 * page header opening its form in a dialog, and removing one from its row
 * menu, for the owner only. On 20 November 2026.
 */
final class FirmVatControllerTest extends WebTestCase
{
    use ListPageAssertions;

    private KernelBrowser $client;
    private SignedInBrowser $browser;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->client->disableReboot();
        $this->browser = new SignedInBrowser($this->client, self::getContainer()->get('doctrine.orm.entity_manager'));
        Clock::set(new MockClock('2026-11-20 10:00:00', 'UTC'));
    }

    protected function tearDown(): void
    {
        Clock::set(new NativeClock());
        parent::tearDown();
    }

    public function testTheTabListsTheStatusesWithTheirDays(): void
    {
        $this->signedIn(Role::Owner);

        $crawler = $this->client->request('GET', '/settings/firm/vat');

        self::assertResponseIsSuccessful();
        self::assertSame('VAT', trim($crawler->filter('main nav[aria-label="Firm sections"] [aria-current="page"]')->text()));
        self::assertCount(1, $crawler->filter('header a#firm-vat-add[data-dialog][href="/settings/firm/vat/new"]'));
        self::assertSelectorTextContains('#firm-vat-today', 'Today: Non-payer');
        self::assertSelectorExists('#firm-vat-undated');
        self::assertSame([['Non-payer', 'from the start', 'until the next change']], $this->rows($crawler));
        self::assertCount(0, $crawler->filter('main [data-row-actions] form'), 'the only status is not removed');
    }

    public function testTheStatusesAreAListWithViews(): void
    {
        $this->signedIn(Role::Owner);
        $values = $this->client->request('GET', '/settings/firm/vat/new')->filter('#firm-vat-form')->form()->getPhpValues();
        $this->client->request('POST', '/settings/firm/vat/new', ['change' => 'registered:quarter', 'validFrom' => '2026-11-12'] + $values);
        self::assertListWithViews(self::getContainer()->get(FirmVatLists::class), 'party.firm_vat', ['status', 'current'], ['all' => false]);

        $crawler = $this->client->request('GET', '/settings/firm/vat');
        self::assertSame(['status', 'from', 'until'], $crawler->filter('[data-firm-vat-statuses] colgroup col[data-column]')->each(static fn (Crawler $col): string => (string) $col->attr('data-column')));
        self::assertListTable($crawler, '[data-firm-vat-statuses]', ['widths' => '']);

        self::assertSame([['VAT payer, quarterly', 'Nov 12, 2026', 'until the next change']], $this->rows($this->client->request('GET', '/settings/firm/vat?q=quarterly')));
        self::assertSame([['Non-payer', 'from the start', 'Nov 11, 2026']], $this->rows(self::applyBuilder($this->client, '/settings/firm/vat', 'status', 'Non')));
    }

    public function testTheOwnerAddsAChangeInTheDialog(): void
    {
        $this->signedIn(Role::Owner);

        $crawler = $this->client->request('GET', '/settings/firm/vat/new', server: [$this->dialogHeader() => '1']);
        self::assertResponseIsSuccessful();
        self::assertCount(1, $crawler->filter('dialog#dialog-page #firm-vat-form'));
        self::assertSame(['registered:month', 'registered:quarter', 'not_registered', 'identified_person'], $crawler->filter('select#change option')->each(static fn (Crawler $option): string => (string) $option->attr('value')));
        self::assertSame(['VAT payer, monthly', 'VAT payer, quarterly', 'Non-payer', 'Identified person'], $crawler->filter('select#change option')->each(static fn (Crawler $option): string => trim($option->text())));
        $values = $crawler->filter('#firm-vat-form')->form()->getPhpValues();

        $this->client->request('POST', '/settings/firm/vat/new', ['change' => 'registered:quarter', 'validFrom' => '2026-11-12'] + $values, server: [$this->dialogHeader() => '1']);

        // Saved, the dialog is told where to go: the tab, with the change in its list.
        self::assertResponseIsSuccessful();
        self::assertSame('{"location":"\\/settings\\/firm\\/vat"}', $this->client->getResponse()->getContent());
        $crawler = $this->client->request('GET', '/settings/firm/vat');
        self::assertSelectorTextContains('main', 'Added: VAT payer, quarterly.');
        self::assertSelectorTextContains('#firm-vat-today', 'Today: VAT payer, quarterly');
        self::assertSame([['Non-payer', 'from the start', 'Nov 11, 2026'], ['VAT payer, quarterly', 'Nov 12, 2026', 'until the next change']], $this->rows($crawler));
        self::assertSame(VatStatus::Registered, $this->firm()->vatStatus());
    }

    public function testAMistakeIsShownAtItsFieldWithTheValuesKept(): void
    {
        $this->signedIn(Role::Owner);
        $values = $this->client->request('GET', '/settings/firm/vat/new')->filter('#firm-vat-form')->form()->getPhpValues();

        $crawler = $this->client->request('POST', '/settings/firm/vat/new', ['change' => 'registered:month', 'validFrom' => '12.11.2026'] + $values);
        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('main', 'Enter a valid date.');
        self::assertSame('12.11.2026', $crawler->filter('#validFrom')->attr('value'));

        $this->client->request('POST', '/settings/firm/vat/new', ['change' => 'payer', 'validFrom' => '2026-11-12'] + $values);
        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('main', 'Choose the VAT status.');

        $this->client->request('POST', '/settings/firm/vat/new', ['change' => 'identified_person', 'validFrom' => '2026-11-12'] + $values);
        $this->client->request('POST', '/settings/firm/vat/new', ['change' => 'not_registered', 'validFrom' => '2026-11-12'] + $values);
        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('main', 'A status already starts on this day.');
    }

    public function testAPayerWithoutAVatIdIsShownAboveTheForm(): void
    {
        $this->signedIn(Role::Owner, vatId: false);
        $values = $this->client->request('GET', '/settings/firm/vat/new')->filter('#firm-vat-form')->form()->getPhpValues();

        $this->client->request('POST', '/settings/firm/vat/new', ['change' => 'registered:month', 'validFrom' => '2026-11-12'] + $values);

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('[data-error-summary]', 'The status of today needs a VAT ID.');
    }

    public function testAForgedFormIsRefused(): void
    {
        $this->signedIn(Role::Owner);

        $this->client->request('POST', '/settings/firm/vat/new', ['change' => 'registered:month', 'validFrom' => '2026-11-12', '_token' => 'forged']);

        self::assertResponseStatusCodeSame(403);
    }

    public function testTheOwnerRemovesAStatusFromItsRow(): void
    {
        $this->signedIn(Role::Owner);
        $values = $this->client->request('GET', '/settings/firm/vat/new')->filter('#firm-vat-form')->form()->getPhpValues();
        $this->client->request('POST', '/settings/firm/vat/new', ['change' => 'registered:month', 'validFrom' => '2026-11-12'] + $values);
        $crawler = $this->client->request('GET', '/settings/firm/vat');
        $forms = $crawler->filter('main [data-row-actions] form');
        self::assertCount(2, $forms);

        $this->client->submit($forms->eq(1)->form());

        self::assertResponseRedirects('/settings/firm/vat');
        $crawler = $this->client->followRedirect();
        self::assertSelectorTextContains('main', 'The status is removed.');
        self::assertSame([['Non-payer', 'from the start', 'until the next change']], $this->rows($crawler));
        self::assertSame(VatStatus::NotRegistered, $this->firm()->vatStatus());

        $this->client->request('POST', '/settings/firm/vat/01937f4e-0000-7000-8000-000000000000/remove', ['_token' => $forms->eq(0)->form()->getValues()['_token']]);
        self::assertResponseStatusCodeSame(404);
    }

    public function testTheLastStatusStays(): void
    {
        $this->signedIn(Role::Owner);
        $values = $this->client->request('GET', '/settings/firm/vat/new')->filter('#firm-vat-form')->form()->getPhpValues();
        $this->client->request('POST', '/settings/firm/vat/new', ['change' => 'registered:month', 'validFrom' => '2026-11-12'] + $values);
        $forms = $this->client->request('GET', '/settings/firm/vat')->filter('main [data-row-actions] form');
        $this->client->submit($forms->eq(1)->form());

        // A page from before still offers to remove the one left.
        $this->client->submit($forms->eq(0)->form());

        self::assertResponseRedirects('/settings/firm/vat');
        $this->client->followRedirect();
        self::assertSelectorTextContains('main', 'The firm keeps at least one status.');
        self::assertSame(1, $this->storedCount());
    }

    public function testAMemberGets403(): void
    {
        $this->signedIn(Role::Member);

        foreach (['/settings/firm/vat', '/settings/firm/vat/new'] as $page) {
            $this->client->request('GET', $page);
            self::assertResponseStatusCodeSame(403, $page);
        }
        foreach (['/settings/firm/vat/new', '/settings/firm/vat/'.$this->firm()->id().'/remove'] as $page) {
            $this->client->request('POST', $page, ['change' => 'registered:month', 'validFrom' => '2026-11-12']);
            self::assertResponseStatusCodeSame(403, $page);
        }
        self::assertSame(0, $this->storedCount());
        $crawler = $this->client->request('GET', '/');
        self::assertCount(0, $crawler->filter('a[href="/settings/firm/vat"]'));
    }

    public function testWithoutAFirmTheTabLeadsToItsSetup(): void
    {
        $this->browser->seed(role: Role::Owner);
        $this->browser->signIn();

        foreach (['/settings/firm/vat', '/settings/firm/vat/new'] as $page) {
            $this->client->request('GET', $page);
            self::assertResponseRedirects('/settings/firm', null, $page);
        }
    }

    private function signedIn(Role $role, bool $vatId = true): void
    {
        $this->browser->seed(role: $role);
        $this->browser->signIn();
        $em = $this->em();
        $em->persist(new LegalEntity('Example Firm s.r.o.', new RegistrationNumber('CZ', '12345695'), $vatId ? VatId::fromString('CZ12345695') : null, new Address('Example Street 1', 'Example City', '100 00', 'CZ'), VatStatus::NotRegistered, ''));
        $em->flush();
    }

    /**
     * @return list<list<string>> the texts of the status, from and until cells of every row
     */
    private function rows(Crawler $crawler): array
    {
        return $crawler->filter('main table tbody tr')->each(static fn (Crawler $row): array => \array_slice($row->filter('td')->each(static function (Crawler $cell): string {
            // The first cell repeats the day below the status, for phones.
            $secondary = $cell->filter('[data-row-secondary]');
            $text = $cell->text();

            return trim(0 === $secondary->count() ? $text : substr($text, 0, -\strlen($secondary->text())));
        }), 0, 3));
    }

    private function firm(): LegalEntity
    {
        $em = $this->em();
        $em->clear();
        $firms = $em->getRepository(LegalEntity::class)->findAll();
        self::assertCount(1, $firms);

        return $firms[0];
    }

    private function storedCount(): int
    {
        return \count($this->em()->getConnection()->fetchFirstColumn('SELECT id FROM party_vat_status_period'));
    }

    private function dialogHeader(): string
    {
        return 'HTTP_'.str_replace('-', '_', strtoupper(DialogRequest::HEADER));
    }

    private function em(): EntityManagerInterface
    {
        return self::getContainer()->get('doctrine.orm.entity_manager');
    }
}
