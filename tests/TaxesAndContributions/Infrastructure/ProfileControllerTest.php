<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\TaxesAndContributions\Infrastructure;

use Foresi\Identity\Domain\Role;
use Foresi\Party\Domain\Address;
use Foresi\Party\Domain\LegalEntity;
use Foresi\Party\Domain\RegistrationNumber;
use Foresi\Party\Domain\VatStatus;
use Foresi\TaxesAndContributions\Application\View\ProfileStatusLists;
use Foresi\TaxesAndContributions\Domain\OwnerProfile;
use Foresi\Tests\Identity\Support\SignedInBrowser;
use Foresi\Tests\TaxesAndContributions\Support\FirmVat;
use Foresi\Tests\Ui\Support\ListPageAssertions;
use Foresi\Ui\Contract\Dialog\DialogRequest;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Clock\Clock;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Clock\NativeClock;
use Symfony\Component\DomCrawler\Crawler;

/**
 * The profile page under Money → Taxes and contributions: the owner adds a
 * change in the dialog, enters a year in the sheet and an authority account;
 * a member sees neither the page nor the menu item.
 */
final class ProfileControllerTest extends WebTestCase
{
    use ListPageAssertions;

    private KernelBrowser $client;
    private SignedInBrowser $browser;

    protected function setUp(): void
    {
        Clock::set(new MockClock('2026-11-20 10:00:00', 'UTC'));
        $this->client = self::createClient();
        $this->client->disableReboot();
        $this->browser = new SignedInBrowser($this->client, self::getContainer()->get('doctrine.orm.entity_manager'));
    }

    protected function tearDown(): void
    {
        Clock::set(new NativeClock());
        parent::tearDown();
    }

    public function testTheOwnerSeesTheStatusesAsOneLineAndChangesThemInTheirDialog(): void
    {
        $this->signedIn(Role::Owner);

        $crawler = $this->client->request('GET', '/taxes-and-contributions/profile');
        self::assertResponseIsSuccessful();
        self::assertCount(1, $crawler->filter('#sidebar [data-nav-sections] a[href="/taxes-and-contributions"]'));
        // A rare change is a small action in the header, not a primary one, and no empty state.
        self::assertCount(1, $crawler->filter('header a[data-taxes-statuses-open][data-dialog][href="/taxes-and-contributions/profile/statuses"]'));
        self::assertCount(0, $crawler->filter('header [data-taxes-status-new]'));
        // Before anything is entered, only the firm's VAT status holds, from the start.
        self::assertSame('Statuses: not a VAT payer', preg_replace('/\s+/', ' ', trim($crawler->filter('[data-taxes-status-summary]')->text())));
        self::assertCount(2, $crawler->filter('[data-taxes-year]'));
        self::assertCount(5, $crawler->filter('[data-taxes-authority]'));

        // The dialog lists the statuses, and "Add a change" in its header loads the form in place.
        $crawler = $this->client->request('GET', '/taxes-and-contributions/profile/statuses', server: [$this->dialogHeader() => '1']);
        self::assertResponseIsSuccessful();
        self::assertCount(1, $crawler->filter('dialog[data-overlay-record] a[data-taxes-status-new][data-in-place]'));
        self::assertCount(0, $crawler->filter('[data-taxes-statuses-empty]'));
        // VAT payer is the firm's: read-only, with the way to the firm's VAT statuses.
        self::assertSame('/settings/firm/vat', $crawler->filter('[data-taxes-kind="vat_payer"] [data-taxes-firm-vat] a')->attr('href'));
        self::assertStringContainsString('From the start', $crawler->filter('[data-taxes-kind="vat_payer"] tbody tr')->text());
        self::assertCount(0, $crawler->filter('[data-taxes-kind="vat_payer"] form'));

        $values = $this->dialogForm('/taxes-and-contributions/profile/statuses/new', '#taxes-status-form');
        self::assertSame('activity:main', $values['change'], 'The dialog starts on a change it may make.');
        $crawler = $this->client->request('GET', '/taxes-and-contributions/profile/statuses/new', server: [$this->dialogHeader() => '1']);
        self::assertCount(0, $crawler->filter('#taxes-status-form option[value^="vat_payer:"]'));
        self::assertCount(1, $crawler->filter('#taxes-status-form option[value="activity:main"]'));
        $this->dialogPost('/taxes-and-contributions/profile/statuses/new', ['change' => 'legal_form:sole_trader', 'validFrom' => '2024-03-01'] + $values);
        $this->dialogPost('/taxes-and-contributions/profile/statuses/new', ['change' => 'activity:main', 'validFrom' => '2024-03-01'] + $values);
        // Saved, the dialog shows the statuses again.
        self::assertSame('{"location":"\\/taxes-and-contributions\\/profile\\/statuses"}', $this->client->getResponse()->getContent());
        // A VAT payer day sent anyway is refused at the change, naming the firm.
        $crawler = $this->dialogPost('/taxes-and-contributions/profile/statuses/new', ['change' => 'vat_payer:yes', 'validFrom' => '2026-11-12'] + $values);
        self::assertResponseStatusCodeSame(422);
        self::assertStringContainsString('VAT payer follows the VAT statuses of the firm', $crawler->filter('#taxes-status-form')->text());
        FirmVat::from($this->browser->em(), 'no', '2026-01-01');
        FirmVat::from($this->browser->em(), 'yes', '2026-11-12');

        $crawler = $this->client->request('GET', '/taxes-and-contributions/profile/statuses');
        self::assertSelectorTextContains('[data-taxes-statuses]', 'VAT payer');
        $rows = $crawler->filter('[data-taxes-kind="vat_payer"] tbody tr');
        self::assertCount(2, $rows);
        self::assertStringContainsString('Not a VAT payer', $rows->eq(0)->text());
        self::assertStringContainsString('Nov 11, 2026', $rows->eq(0)->text());
        self::assertStringContainsString('until the next change', $rows->eq(1)->text());
        self::assertCount(0, $rows->filter('form'), 'The firm\'s VAT statuses are removed on the firm.');
        self::assertSame('confirm-submit', $crawler->filter('[data-taxes-kind="activity"] tbody tr form')->attr('data-controller'));

        // The page: one line of what holds today, and the change in the year it falls in.
        $crawler = $this->client->request('GET', '/taxes-and-contributions/profile');
        self::assertSame('Statuses: sole trader · main activity · VAT payer from Nov 12, 2026', preg_replace('/\s+/', ' ', trim($crawler->filter('[data-taxes-status-summary]')->text())));
        self::assertSame('from Jan 1: not a VAT payer · from Nov 12: VAT payer', $crawler->filter('[data-taxes-year="2026"] [data-taxes-year-changes]')->text());
        self::assertCount(0, $crawler->filter('[data-taxes-year="2027"] [data-taxes-year-changes]'));

        // The same day again is refused in the dialog, at the day.
        $crawler = $this->dialogPost('/taxes-and-contributions/profile/statuses/new', ['change' => 'activity:secondary', 'validFrom' => '2024-03-01'] + $values);
        self::assertResponseStatusCodeSame(422);
        self::assertStringContainsString('already starts on this day', $crawler->filter('#taxes-status-form')->text());
        $crawler = $this->dialogPost('/taxes-and-contributions/profile/statuses/new', ['change' => 'sickness_insurance:on', 'validFrom' => '2026-01-01', 'base' => ''] + $values);
        self::assertResponseStatusCodeSame(422);
        self::assertStringContainsString('Enter the monthly base', $crawler->filter('#taxes-status-form')->text());
    }

    public function testTheStatusesAreOneListWithViewsShownAsATablePerKind(): void
    {
        $this->signedIn(Role::Owner);
        $values = $this->dialogForm('/taxes-and-contributions/profile/statuses/new', '#taxes-status-form');
        $this->dialogPost('/taxes-and-contributions/profile/statuses/new', ['change' => 'legal_form:sole_trader', 'validFrom' => '2024-03-01'] + $values);
        $this->dialogPost('/taxes-and-contributions/profile/statuses/new', ['change' => 'activity:main', 'validFrom' => '2024-03-01'] + $values);
        self::assertListWithViews(self::getContainer()->get(ProfileStatusLists::class), 'taxes.profile_statuses', ['value', 'kind'], ['all' => false]);
        $kinds = static fn (Crawler $crawler): array => $crawler->filter('[data-taxes-statuses] [data-taxes-kind]')->each(static fn (Crawler $group): string => (string) $group->attr('data-taxes-kind'));

        $crawler = $this->client->request('GET', '/taxes-and-contributions/profile/statuses');
        // One toolbar for the page, and every table has the same columns.
        self::assertCount(1, $crawler->filter('[data-taxes-statuses] [data-list-toolbar]'));
        self::assertSame(['legal_form', 'activity', 'vat_payer'], $kinds($crawler));
        foreach ($crawler->filter('[data-taxes-kind] colgroup')->each(static fn (Crawler $cols): array => $cols->filter('col[data-column]')->each(static fn (Crawler $col): string => (string) $col->attr('data-column'))) as $columns) {
            self::assertSame(['value', 'from', 'until'], $columns);
        }
        self::assertListTable($crawler, '[data-taxes-statuses]', ['widths' => '']);

        // A search keeps the kinds that have a row left, and none shows the way out.
        self::assertSame(['activity'], $kinds($this->client->request('GET', '/taxes-and-contributions/profile/statuses?q=main')));
        $crawler = $this->client->request('GET', '/taxes-and-contributions/profile/statuses?q=zzz');
        self::assertSame([], $kinds($crawler));
        self::assertCount(1, $crawler->selectLink('Clear the filter'));
        self::assertSame(['legal_form'], $kinds(self::applyBuilder($this->client, '/taxes-and-contributions/profile/statuses', 'value', 'Sole')));
    }

    public function testTheOwnerEntersAYearInThreeSheetsAndAnAuthorityAccount(): void
    {
        $this->signedIn(Role::Owner);
        $crawler = $this->client->request('GET', '/taxes-and-contributions/profile');
        self::assertCount(3, $crawler->filter('[data-taxes-year="2026"] [data-taxes-year-section] a[data-sheet]'));
        self::assertSame('/taxes-and-contributions/profile/years/2026/credits', $crawler->filter('[data-taxes-year="2026"] [data-taxes-year-section="credits"] a')->attr('href'));

        // The expenses, in their own sheet; /years/2026 is that sheet.
        $crawler = $this->client->request('GET', '/taxes-and-contributions/profile/years/2026', server: [$this->dialogHeader() => '1']);
        self::assertResponseIsSuccessful();
        self::assertSame('expenses', $crawler->filter('#taxes-year-form')->attr('data-taxes-section'));
        self::assertCount(0, $crawler->filter('[data-taxes-children]'));
        $form = $crawler->filter('#taxes-year-form')->form();
        $form['chosenMethod'] = 'lump_60';
        $this->client->submit($form, [], [$this->dialogHeader() => '1']);
        self::assertSame('{"location":"\\/taxes-and-contributions\\/profile"}', $this->client->getResponse()->getContent());

        // The children: none at first, "Add a child" answers with a blank one, nothing saved yet.
        $crawler = $this->client->request('GET', '/taxes-and-contributions/profile/years/2026/credits', server: [$this->dialogHeader() => '1']);
        self::assertCount(0, $crawler->filter('[data-taxes-child]'));
        self::assertCount(1, $crawler->filter('[data-taxes-children-empty]'));
        $crawler = $this->client->submit($crawler->filter('[data-taxes-child-add]')->form(), [], [$this->dialogHeader() => '1']);
        self::assertResponseIsSuccessful();
        self::assertCount(1, $crawler->filter('[data-taxes-child]'));
        self::assertSame('12', $crawler->filter('#child-0-months')->attr('value'));
        self::assertNull($crawler->filter('[data-taxes-child-more]')->attr('open'));
        $form = $crawler->filter('[data-taxes-child-add]')->form();
        $form->setValues(['children' => [['label' => '', 'birthDate' => '2022-09-01']]]);
        $crawler = $this->client->submit($form, [], [$this->dialogHeader() => '1']);
        self::assertCount(2, $crawler->filter('[data-taxes-child]'));
        self::assertSame('2022-09-01', $crawler->filter('#child-0-birth')->attr('value'));
        $form = $crawler->filter('#taxes-year-form')->form();
        $form->setValues(['children' => [1 => ['label' => 'Elder daughter', 'birthDate' => '2019-05-14']], 'deductions' => ['pension_products' => '24 000']]);
        $form['spouseMonths'] = '6';
        $this->client->submit($form, [], [$this->dialogHeader() => '1']);
        self::assertSame('{"location":"\\/taxes-and-contributions\\/profile"}', $this->client->getResponse()->getContent());

        // By birth date, the eldest first; the unnamed one shows by its place.
        $crawler = $this->client->request('GET', '/taxes-and-contributions/profile/years/2026/credits');
        self::assertSame('Elder daughter', $crawler->filter('#child-0-label')->attr('value'));
        self::assertSame('', $crawler->filter('#child-1-label')->attr('value'));
        self::assertStringContainsString('Child 2', $crawler->filter('[data-taxes-child]')->eq(1)->text());
        self::assertSame('24000.00', $crawler->filter('#deduction-pension_products')->attr('value'));
        self::assertSame('6', $crawler->filter('#spouseMonths')->attr('value'));
        // The expenses saved before are kept.
        $crawler = $this->client->request('GET', '/taxes-and-contributions/profile/years/2026/expenses');
        self::assertSame('lump_60', $crawler->filter('#chosenMethod option[selected]')->attr('value'));

        // Remove takes the child out of the form; Save stores it.
        $crawler = $this->client->request('GET', '/taxes-and-contributions/profile/years/2026/credits', server: [$this->dialogHeader() => '1']);
        $crawler = $this->client->submit($crawler->filter('[data-taxes-child-remove]')->eq(0)->form([], 'POST'), ['_action' => 'remove_child:0'], [$this->dialogHeader() => '1']);
        self::assertCount(1, $crawler->filter('[data-taxes-child]'));
        $form = $crawler->filter('#taxes-year-form')->form();
        $form->setValues(['children' => [['months' => '13']]]);
        $crawler = $this->client->submit($form, [], [$this->dialogHeader() => '1']);
        self::assertResponseStatusCodeSame(422);
        self::assertStringContainsString('The months are 0 to 12', $crawler->filter('#taxes-year-form')->text());
        self::assertNotNull($crawler->filter('[data-taxes-child-more]')->attr('open'));

        // The other details, in the third sheet.
        $crawler = $this->client->request('GET', '/taxes-and-contributions/profile/years/2026/other', server: [$this->dialogHeader() => '1']);
        $form = $crawler->filter('#taxes-year-form')->form();
        $form['employmentBase'] = '120 000,50';
        $form['previousActivityMonths'] = '5x';
        $crawler = $this->client->submit($form, [], [$this->dialogHeader() => '1']);
        self::assertResponseStatusCodeSame(422);
        self::assertStringContainsString('The months of activity are 1 to 12', $crawler->filter('#taxes-year-form')->text());
        $form = $crawler->filter('#taxes-year-form')->form();
        $form['previousActivityMonths'] = '13';
        $crawler = $this->client->submit($form, [], [$this->dialogHeader() => '1']);
        self::assertResponseStatusCodeSame(422);
        self::assertStringContainsString('The months of activity are 1 to 12', $crawler->filter('#taxes-year-form')->text());
        $form = $crawler->filter('#taxes-year-form')->form();
        $form['previousActivityMonths'] = '5';
        $this->client->submit($form, [], [$this->dialogHeader() => '1']);
        self::assertSame('{"location":"\\/taxes-and-contributions\\/profile"}', $this->client->getResponse()->getContent());
        $crawler = $this->client->request('GET', '/taxes-and-contributions/profile/years/2026/other');
        self::assertSame('120000.50', $crawler->filter('#employmentBase')->attr('value'));
        self::assertSame('5', $crawler->filter('#previousActivityMonths')->attr('value'));

        $crawler = $this->client->request('GET', '/taxes-and-contributions/profile/authorities/health', server: [$this->dialogHeader() => '1']);
        $form = $crawler->filter('#taxes-authority-form')->form(['account' => '1234567890/0300', 'variableSymbol' => '8501011234']);
        $this->client->submit($form, [], [$this->dialogHeader() => '1']);
        self::assertSame('{"location":"\\/taxes-and-contributions\\/profile"}', $this->client->getResponse()->getContent());

        $crawler = $this->client->request('GET', '/taxes-and-contributions/profile');
        self::assertStringContainsString('1234567890/0300 · VS 8501011234', $crawler->filter('[data-taxes-authority="health"]')->text());
        self::assertStringContainsString('2 children', $crawler->filter('[data-taxes-year="2026"] [data-taxes-year-section="credits"]')->text());
        self::assertStringContainsString('Lump sum 60% in the return', $crawler->filter('[data-taxes-year="2026"] [data-taxes-year-section="expenses"]')->text());
        self::assertNotNull($this->browser->em()->find(OwnerProfile::class, OwnerProfile::ID));
    }

    public function testAMemberIsRefusedWithOwnerOnly(): void
    {
        $this->signedIn(Role::Member);

        foreach (['/taxes-and-contributions/profile', '/taxes-and-contributions/profile/statuses', '/taxes-and-contributions/profile/statuses/new', '/taxes-and-contributions/profile/years/2026', '/taxes-and-contributions/profile/authorities/health'] as $page) {
            $this->client->request('GET', $page);
            self::assertResponseStatusCodeSame(403, $page);
        }
        $this->client->request('POST', '/taxes-and-contributions/profile/statuses/new', ['change' => 'activity:main', 'validFrom' => '2026-11-12']);
        self::assertResponseStatusCodeSame(403);

        self::assertNull($this->browser->em()->find(OwnerProfile::class, OwnerProfile::ID));
    }

    private function signedIn(Role $role): void
    {
        $this->browser->seed(role: $role);
        $this->browser->signIn();
        $em = $this->browser->em();
        $em->persist(new LegalEntity('Example Sole Trader', new RegistrationNumber('CZ', '12345695'), null, new Address('Example Street 1', 'Example City', '100 00', 'CZ'), VatStatus::NotRegistered, ''));
        $em->flush();
    }

    private function dialogHeader(): string
    {
        return 'HTTP_'.str_replace('-', '_', strtoupper(DialogRequest::HEADER));
    }

    /**
     * @return array<string, string>
     */
    private function dialogForm(string $url, string $form): array
    {
        $crawler = $this->client->request('GET', $url, server: [$this->dialogHeader() => '1']);
        self::assertResponseIsSuccessful();
        self::assertCount(1, $crawler->filter('dialog#dialog-page'));

        /** @var array<string, string> $values */
        $values = $crawler->filter($form)->form()->getPhpValues();

        return $values;
    }

    /**
     * @param array<string, string> $values
     */
    private function dialogPost(string $url, array $values): Crawler
    {
        return $this->client->request('POST', $url, $values, server: [$this->dialogHeader() => '1']);
    }
}
