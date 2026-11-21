<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\TaxesAndContributions\Infrastructure;

use Foresi\CashFlow\Application\CreatePlannedItemInput;
use Foresi\CashFlow\Application\PlannedItemWrites;
use Foresi\Identity\Domain\Role;
use Foresi\Identity\Domain\User;
use Foresi\Identity\Infrastructure\Security\SecurityUser;
use Foresi\Invoicing\Integration\TaxesAndContributions\IssuedPaymentAmounts;
use Foresi\Party\Domain\Address;
use Foresi\Party\Domain\LegalEntity;
use Foresi\Party\Domain\RegistrationNumber;
use Foresi\Party\Domain\VatStatus;
use Foresi\Shared\Domain\Currency;
use Foresi\Shared\Domain\Money;
use Foresi\TaxesAndContributions\Application\AddStatusInput;
use Foresi\TaxesAndContributions\Application\ProfileWrites;
use Foresi\TaxesAndContributions\Application\SetYearInputsInput;
use Foresi\TaxesAndContributions\Contract\AmountKind;
use Foresi\TaxesAndContributions\Contract\Authority;
use Foresi\TaxesAndContributions\Contract\DuePayment;
use Foresi\TaxesAndContributions\Contract\EstimateView;
use Foresi\TaxesAndContributions\Contract\OpenAmount;
use Foresi\TaxesAndContributions\Contract\Subject;
use Foresi\TaxesAndContributions\Infrastructure\Screen\TaxesScreen;
use Foresi\Tests\Identity\Support\SignedInBrowser;
use Foresi\Tests\TaxesAndContributions\Support\DatedAmountSource;
use Foresi\Ui\Contract\Dialog\DialogRequest;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Clock\Clock;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Clock\NativeClock;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;

/**
 * The page Money → Taxes and contributions: what the owner keeps to date
 * beside the year end, the methods compared, the credits, and the steps of
 * every number in the sheet; a member sees neither the page nor the item.
 */
final class TaxesControllerTest extends WebTestCase
{
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

    public function testTheOwnerSeesWhatTheyKeepToDateBesideTheYearEnd(): void
    {
        $this->signedIn(Role::Owner);
        $this->planned('in', 'revenue', '10000.00', '2026-12-15');
        $this->planned('out', 'taxes', '5005.00', '2026-12-31');

        $crawler = $this->client->request('GET', '/taxes-and-contributions');
        self::assertResponseIsSuccessful();
        self::assertCount(1, $crawler->filter('#sidebar a[href="/taxes-and-contributions"]'));
        self::assertCount(1, $crawler->filter('header a[data-taxes-profile][href="/taxes-and-contributions/profile"]'));
        self::assertSame(['2025', '2026', '2027'], $crawler->filter('[data-taxes-years] a')->each(static fn (Crawler $tab): string => trim($tab->text())));
        self::assertSame('2026', trim($crawler->filter('[data-taxes-years] a[aria-current="page"]')->text()));
        self::assertSame('/taxes-and-contributions?year=2027', $crawler->filter('[data-taxes-years] a')->eq(2)->attr('href'));

        // One table: the rows to date, and the year end apart, under the forecast badge, in its own dashed column.
        self::assertSelectorTextContains('[data-taxes-summary] h2', 'What you keep');
        self::assertSame('Method: Actual costs (none in the profile, the cheaper one shown)', $this->text($crawler, '[data-taxes-method]'));
        self::assertSame(['income', 'costs', 'social', 'health', 'sickness', 'income_tax', 'child_bonus', 'levies', 'take_home'], $crawler->filter('[data-taxes-row]')->each(static fn (Crawler $row): string => (string) $row->attr('data-taxes-row')));
        self::assertSame('To date', $this->text($crawler, '[data-taxes-to-date]'));
        self::assertSame('Forecast At year end', $this->text($crawler, '[data-taxes-year-end]'));
        self::assertCount(1, $crawler->filter('[data-taxes-year-end] [data-taxes-forecast-badge]'));
        self::assertCount(0, $crawler->filter('[data-taxes-year-end-empty]'));
        self::assertCount(9, $crawler->filter('[data-taxes-year-end-cell]'));
        self::assertStringContainsString('border-dashed', (string) $crawler->filter('[data-taxes-year-end-cell="social"]')->attr('class'));
        // Every month of the year counts, and the planned income as stated; the planned tax is left out.
        self::assertSame('/taxes-and-contributions/steps/2026/actual/social?part=year_end', $crawler->filter('[data-taxes-year-end-cell="social"] a[data-sheet]')->attr('href'));
        self::assertStringEndsWith('– show how the year end is computed', $this->text($crawler, '[data-taxes-year-end-cell="social"] a'));
        self::assertSame("10,000\u{a0}Kč – show how the year end is computed", $this->text($crawler, '[data-taxes-year-end-cell="income"] a'));
        self::assertSame("0\u{a0}Kč – show how the year end is computed", $this->text($crawler, '[data-taxes-year-end-cell="costs"] a'));
        self::assertSame("0\u{a0}Kč – show how it is computed", $this->text($crawler, '[data-taxes-row="income"] td a'));
        self::assertSame('/taxes-and-contributions/steps/2026/actual/all?part=year_end', $crawler->filter('[data-taxes-year-end-cell="take_home"] a')->attr('href'));
        // Under it, the forecast part by certainty, with what it could not split.
        self::assertSame(['document', 'schedule', 'plan'], $crawler->filter('[data-taxes-forecast-row]')->each(static fn (Crawler $row): string => (string) $row->attr('data-taxes-forecast-row')));
        self::assertSame("Planned items Income 10,000\u{a0}Kč Costs 0\u{a0}Kč", $this->text($crawler, '[data-taxes-forecast-row="plan"]'));
        self::assertSame("Open documents Income 0\u{a0}Kč Costs 0\u{a0}Kč", $this->text($crawler, '[data-taxes-forecast-row="document"]'));
        self::assertSame('recurring invoices and planned items count as entered, with no VAT split', $this->text($crawler, '[data-taxes-forecast-notes]'));
        // No income yet, main activity assumed January to November: eleven months of the minimum base 17,139 at 29.2 %.
        $social = $crawler->filter('[data-taxes-row="social"] a[data-sheet]');
        self::assertSame('/taxes-and-contributions/steps/2026/actual/social', $social->attr('href'));
        self::assertSame("55,051\u{a0}Kč – show how it is computed", $this->text($crawler, '[data-taxes-row="social"] a[data-sheet]'));
        self::assertSame('/taxes-and-contributions/steps/2026/actual/income_tax', $crawler->filter('[data-taxes-row="income_tax"] a')->attr('href'));
        self::assertSame('/taxes-and-contributions/steps/2026/actual/child_benefit', $crawler->filter('[data-taxes-row="child_bonus"] a')->attr('href'));
        self::assertSame('/taxes-and-contributions/steps/2026/actual/all', $crawler->filter('[data-taxes-row="take_home"] a')->attr('href'));
        self::assertStringStartsWith('-', $this->text($crawler, '[data-taxes-row="take_home"] a'));

        // The methods side by side, the cheaper marked, the break-even above them.
        self::assertSame(['actual', 'lump_60'], $crawler->filter('[data-taxes-method-column]')->each(static fn (Crawler $column): string => (string) $column->attr('data-taxes-method-column')));
        self::assertCount(1, $crawler->filter('[data-taxes-method-column="actual"] [data-taxes-cheaper]'));
        self::assertSame('Actual costs already cost as much as Lump sum 60% or less.', $this->text($crawler, '[data-taxes-break-even]'));
        self::assertSame(['profit', 'levies', 'take_home'], $crawler->filter('[data-taxes-comparison-row]')->each(static fn (Crawler $row): string => (string) $row->attr('data-taxes-comparison-row')));
        self::assertSame('/taxes-and-contributions/steps/2026/lump_60/profit', $crawler->filter('[data-taxes-comparison-row="profit"] a')->eq(1)->attr('href'));

        // The credits, translated by their key, the amounts from the rule set.
        self::assertStringContainsString("Taxpayer credit Every taxpayer: 30,840\u{a0}Kč a year. 30,840\u{a0}Kč Claimed", $this->text($crawler, '[data-taxes-credit="taxpayer"]'));
        self::assertStringContainsString('Not claimed', $this->text($crawler, '[data-taxes-credit="disability"]'));
        self::assertStringContainsString("from 100\u{a0}Kč when the income reaches 134,400\u{a0}Kč", $this->text($crawler, '[data-taxes-credit="child_benefit"]'));

        self::assertStringContainsString('activity is assumed', $this->text($crawler, '[data-taxes-warnings]'));
        self::assertStringContainsString('An estimate, not a basis for the tax return.', $this->text($crawler, '[data-taxes-footer]'));
        self::assertSame('The rules of 2026: published by the authorities.', $this->text($crawler, '[data-taxes-rules]'));
        self::assertStringContainsString('the profit as the base of the insurance', $this->text($crawler, '[data-taxes-unverified]'));
        self::assertStringContainsString('carrying a tax loss forward', $this->text($crawler, '[data-taxes-gaps]'));
    }

    public function testTheStepsOfTheYearEndOpenInTheSheet(): void
    {
        $this->signedIn(Role::Owner);

        $crawler = $this->client->request('GET', '/taxes-and-contributions/steps/2026/actual/social?part=year_end', server: [$this->dialogHeader() => DialogRequest::SHEET]);
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('Social insurance at the year end · Actual costs', $crawler->filter('dialog#sheet-page')->text());
        self::assertStringContainsString('Months of main activity 12', $this->text($crawler, '[data-taxes-step="social"] [data-taxes-step-input]'));

        $crawler = $this->client->request('GET', '/taxes-and-contributions/steps/2026/actual/social?part=other', server: [$this->dialogHeader() => DialogRequest::SHEET]);
        self::assertStringContainsString('Social insurance · Actual costs', $crawler->filter('dialog#sheet-page')->text());
        self::assertStringContainsString('Months of main activity 11', $this->text($crawler, '[data-taxes-step="social"] [data-taxes-step-input]'));

        $this->client->request('GET', '/taxes-and-contributions/steps/2027/actual/social?part=year_end');
        self::assertResponseStatusCodeSame(404);
    }

    public function testTheMethodChosenInTheProfileIsTheOneShown(): void
    {
        $this->signedIn(Role::Owner);
        $crawler = $this->client->request('GET', '/taxes-and-contributions/profile/years/2026', server: [$this->dialogHeader() => '1']);
        $form = $crawler->filter('#taxes-year-form')->form();
        $form['chosenMethod'] = 'lump_60';
        $this->client->submit($form, [], [$this->dialogHeader() => '1']);

        $crawler = $this->client->request('GET', '/taxes-and-contributions?year=2026');
        self::assertSame('Method: Lump sum 60% (chosen in the profile)', $this->text($crawler, '[data-taxes-method]'));
        self::assertSame('/taxes-and-contributions/steps/2026/lump_60/profit', $crawler->filter('[data-taxes-row="income"] a')->attr('href'));
    }

    public function testNextYearAndAYearWithoutRulesSayWhy(): void
    {
        $this->signedIn(Role::Owner);

        $crawler = $this->client->request('GET', '/taxes-and-contributions?year=2027');
        self::assertResponseIsSuccessful();
        self::assertSame('2027', trim($crawler->filter('[data-taxes-years] a[aria-current="page"]')->text()));
        self::assertStringContainsString('The year 2027 has not started yet', $this->text($crawler, '[data-taxes-empty="year_not_started"]'));
        self::assertCount(0, $crawler->filter('[data-taxes-summary], [data-taxes-comparison], [data-taxes-credits], [data-taxes-rules]'));
        self::assertStringContainsString('An estimate, not a basis for the tax return.', $this->text($crawler, '[data-taxes-footer]'));

        $crawler = $this->client->request('GET', '/taxes-and-contributions?year=2025');
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('No rules for the year 2025', $this->text($crawler, '[data-taxes-empty="rules_not_available"]'));
        self::assertCount(0, $crawler->filter('[data-taxes-summary]'));

        foreach (['/taxes-and-contributions?year=2028', '/taxes-and-contributions?year=last', '/taxes-and-contributions/steps/2027/actual/social', '/taxes-and-contributions/steps/2026/actual/unknown', '/taxes-and-contributions/steps/2026/lump_99/social', '/taxes-and-contributions/steps/2026/lump_80/social'] as $page) {
            $this->client->request('GET', $page);
            self::assertResponseStatusCodeSame(404, $page);
        }
    }

    public function testTheStepsOfSocialOpenInTheSheetWithTheRuleAndItsSource(): void
    {
        $this->signedIn(Role::Owner);

        $crawler = $this->client->request('GET', '/taxes-and-contributions/steps/2026/actual/social', server: [$this->dialogHeader() => DialogRequest::SHEET]);
        self::assertResponseIsSuccessful();
        self::assertCount(1, $crawler->filter('dialog#sheet-page'));
        self::assertStringContainsString('Social insurance · Actual costs', $crawler->filter('dialog#sheet-page')->text());
        self::assertCount(1, $crawler->filter('[data-taxes-step]'));
        $step = $crawler->filter('[data-taxes-step="social"]');
        self::assertSame('A share of the profit, at least the minimum base of each month taking part, at most the maximum base; times 29.2 %, rounded up.', str_replace("\u{a0}", ' ', $this->text($step, '[data-taxes-step-rule]')));
        self::assertStringStartsWith('https://', (string) $step->filter('a[data-taxes-step-source]')->attr('href'));
        self::assertSame('_blank', $step->filter('a[data-taxes-step-source]')->attr('target'));
        self::assertSame("55,051\u{a0}Kč", $this->text($step, '[data-taxes-step-result]'));
        $input = $this->text($step, '[data-taxes-step-input]');
        self::assertStringContainsString('Months of main activity 11', $input);
        self::assertStringContainsString("Minimum base 188,529\u{a0}Kč", $input);
        self::assertStringContainsString("Rate 29.2\u{a0}%", $input);

        // The income tax opens the steps it is made of; the take-home the whole calculation.
        $crawler = $this->client->request('GET', '/taxes-and-contributions/steps/2026/lump_60/income_tax', server: [$this->dialogHeader() => DialogRequest::SHEET]);
        self::assertSame(['tax_base', 'income_tax', 'credits', 'child_benefit'], $crawler->filter('[data-taxes-step]')->each(static fn (Crawler $item): string => (string) $item->attr('data-taxes-step')));
        self::assertSame("15\u{a0}% of the base up to 1,762,812\u{a0}Kč, 23\u{a0}% of the part above; rounded up.", $this->text($crawler, '[data-taxes-step="income_tax"] [data-taxes-step-rule]'));
        $crawler = $this->client->request('GET', '/taxes-and-contributions/steps/2026/lump_60/profit', server: [$this->dialogHeader() => DialogRequest::SHEET]);
        self::assertStringContainsString("Income less 60\u{a0}% of it as the lump sum, at most 1,200,000\u{a0}Kč", $this->text($crawler, '[data-taxes-step-rule]'));
        self::assertStringContainsString('Expense method Lump sum 60%', $this->text($crawler, '[data-taxes-step-input]'));
        $crawler = $this->client->request('GET', '/taxes-and-contributions/steps/2026/actual/all', server: [$this->dialogHeader() => DialogRequest::SHEET]);
        self::assertCount(8, $crawler->filter('[data-taxes-step]'), 'An ordinary year has no flat-rate step.');
        self::assertSame('Income less the costs actually paid, both on the cash basis.', $this->text($crawler, '[data-taxes-step="profit"] [data-taxes-step-rule]'));

        // Without the sheet, the same URL is a page.
        $crawler = $this->client->request('GET', '/taxes-and-contributions/steps/2026/actual/social');
        self::assertResponseIsSuccessful();
        self::assertCount(0, $crawler->filter('dialog#sheet-page'));
        self::assertCount(1, $crawler->filter('nav a[href="/taxes-and-contributions?year=2026"]'));
    }

    public function testAFlatRateYearShowsItsOwnRowAndSteps(): void
    {
        $this->signedIn(Role::Owner);
        $writes = self::getContainer()->get(ProfileWrites::class);
        $writes->addStatus(new AddStatusInput('activity', 'main', '2026-01-01'));
        $writes->addStatus(new AddStatusInput('flat_rate_band', '2', '2026-01-01'));

        $crawler = $this->client->request('GET', '/taxes-and-contributions');
        self::assertResponseIsSuccessful();
        self::assertSame(['income', 'costs', 'social', 'health', 'sickness', 'income_tax', 'flat_rate', 'child_bonus', 'levies', 'take_home'], $crawler->filter('[data-taxes-row]')->each(static fn (Crawler $row): string => (string) $row->attr('data-taxes-row')));
        $row = $crawler->filter('[data-taxes-row="flat_rate"]');
        self::assertStringContainsString('Flat-rate tax', $row->text());
        self::assertStringContainsString("184,195\u{a0}Kč", $row->text(), 'Eleven months of 16,745.');
        self::assertSame('/taxes-and-contributions/steps/2026/actual/flat_rate', $row->filter('td a')->attr('href'));
        self::assertStringContainsString('Flat-rate tax – advance 12/2026', $crawler->filter('[data-taxes-payment="flat_rate.advance.2026-12"]')->text());
        self::assertCount(0, $crawler->filter('[data-taxes-credits]'), 'No credit applies.');

        $crawler = $this->client->request('GET', '/taxes-and-contributions/steps/2026/actual/flat_rate', server: [$this->dialogHeader() => DialogRequest::SHEET]);
        self::assertResponseIsSuccessful();
        $step = $crawler->filter('[data-taxes-step="flat_rate"]');
        self::assertStringContainsString('replaces social, health and income tax', $this->text($step, '[data-taxes-step-rule]'));
        self::assertStringContainsString('Months in band 2 11', $this->text($step, '[data-taxes-step-input]'));
        self::assertSame("184,195\u{a0}Kč", $this->text($step, '[data-taxes-step-result]'));
        $crawler = $this->client->request('GET', '/taxes-and-contributions/steps/2026/actual/social', server: [$this->dialogHeader() => DialogRequest::SHEET]);
        self::assertStringContainsString('Every active month is in the flat-rate tax', $this->text($crawler, '[data-taxes-step-rule]'));
        $crawler = $this->client->request('GET', '/taxes-and-contributions/steps/2026/actual/all', server: [$this->dialogHeader() => DialogRequest::SHEET]);
        self::assertCount(9, $crawler->filter('[data-taxes-step]'));
    }

    public function testAMethodSwitchIsAWarningWithItsAmountAndAPaymentOfItsOwn(): void
    {
        Clock::set(new MockClock('2027-03-10 10:00:00', 'UTC'));
        $this->signedIn(Role::Owner);
        $writes = self::getContainer()->get(ProfileWrites::class);
        $writes->addStatus(new AddStatusInput('activity', 'main', '2026-01-01'));
        $writes->setYear(new SetYearInputsInput('2026', ['actual'], 'actual'));
        $writes->setYear(new SetYearInputsInput('2027', ['lump_60'], 'lump_60'));
        $open = new OpenAmount('invoicing.payments', new Subject('invoice', 'open', null), AmountKind::Income, Money::ofMinor(50_000_00, Currency::CZK), Money::ofMinor(50_000_00, Currency::CZK), Money::ofMinor(0, Currency::CZK));
        self::getContainer()->set(IssuedPaymentAmounts::class, new DatedAmountSource('invoicing.payments', [], ['2026-12-31' => [$open]]));

        $crawler = $this->client->request('GET', '/taxes-and-contributions');
        self::assertResponseIsSuccessful();
        $alert = $this->text($crawler, '[data-taxes-switch]');
        self::assertStringContainsString('The expense method changes from Actual costs to Lump sum 60%, so 2026 gets an additional return', $alert);
        self::assertStringContainsString("its tax base changes by 50,000\u{a0}Kč, the receivables of 50,000\u{a0}Kč open on", $alert);
        self::assertStringContainsString("Its tax of 7,500\u{a0}Kč is due by", $alert);
        self::assertStringNotContainsString('tax base is adjusted with an additional return', $this->text($crawler, '[data-taxes-warnings]'), 'Said once, with the amount.');
        self::assertStringContainsString("Income tax – method change, additional return 2027 7,500\u{a0}Kč", $this->text($crawler, '[data-taxes-payment="income_tax.switch_adjustment.2027"]'));
        self::assertStringContainsString("the adjustment of last year's base for the change of the expense method", $this->text($crawler, '[data-taxes-unverified]'));
        self::assertNotContains('switch_adjustment', $crawler->filter('[data-taxes-row]')->each(static fn (Crawler $row): string => (string) $row->attr('data-taxes-row')), 'Never a row of the totals.');

        $crawler = $this->client->request('GET', '/taxes-and-contributions?year=2026');
        self::assertCount(0, $crawler->filter('[data-taxes-switch]'), 'The year adjusted shows nothing of it.');
    }

    public function testThePaymentsShowWhatFallsDueAndThePlannedItemsThatMayDuplicateThem(): void
    {
        $this->signedIn(Role::Owner);
        $this->planned('out', 'taxes', '5005.00', '2026-12-31');

        $crawler = $this->client->request('GET', '/taxes-and-contributions');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('[data-taxes-payments] h2', 'Payments');
        $december = $crawler->filter('[data-taxes-payment="social.advance.2026-12"]');
        self::assertSame('expected', $december->attr('data-state'));
        self::assertStringContainsString('Social insurance – advance 12/2026', $december->text());
        self::assertStringContainsString('5,005', $december->text());
        self::assertStringContainsString('Dec 31, 2026', $december->text());
        self::assertSame('Overdue', trim($crawler->filter('[data-taxes-payment="social.advance.2026-10"] td:last-child')->text()));
        // Without income, the advances to June at 5,720 paid more than the year's minimum: it comes back.
        self::assertStringContainsString('+', $crawler->filter('[data-taxes-payment="social.overpayment.2026"]')->text());
        self::assertCount(0, $crawler->filter('[data-taxes-paid]'), 'Nothing is paid yet.');

        $duplicate = $crawler->filter('[data-taxes-duplicates] [data-taxes-duplicate] a');
        self::assertSame('Planned taxes', trim($duplicate->text()));
        self::assertMatchesRegularExpression('#^/cash-flow/planned-costs/[0-9a-f-]{36}$#', (string) $duplicate->attr('href'));
        self::assertStringNotContainsString('may count a levy twice.', $this->text($crawler, '[data-taxes-warnings]'), 'The duplicates are named in the payments, not twice.');
        self::assertStringContainsString('no account in the profile', $this->text($crawler, '[data-taxes-warnings]'));
    }

    public function testAPaidPaymentSaysWhenTheBankBookedItInCzech(): void
    {
        $this->signedIn(Role::Owner);
        $day = static fn (string $day): \DateTimeImmutable => new \DateTimeImmutable($day, new \DateTimeZone('Europe/Prague'));
        $czk = Money::ofMinor(500_500, Currency::CZK);
        $paid = new DuePayment(Authority::Social, DuePayment::KIND_ADVANCE, '2026-10', $day('2026-10-01'), $day('2026-10-31'), $czk, DuePayment::STATE_PAID, '1/0100', '1', 'm1', $day('2026-10-30'));
        $open = new DuePayment(Authority::Health, DuePayment::KIND_ADVANCE, '2026-11', $day('2026-11-01'), $day('2026-12-08'), $czk);
        $estimate = new EstimateView(2026, $day('2026-11-20'), null, null, null, null, null, [], [], [], payments: [$paid, $open]);

        $rows = self::getContainer()->get(TaxesScreen::class)->payments($estimate);

        self::assertSame([DuePayment::STATE_PAID, 'Zaplaceno 30. 10. 2026', 'green'], [$rows[0]['state'], $rows[0]['stateLabel'], $rows[0]['tone']]);
        self::assertSame(['Očekává se', 'outline', 'Zdravotní pojištění – záloha 11/2026'], [$rows[1]['stateLabel'], $rows[1]['tone'], $rows[1]['label']]);
    }

    public function testAMemberIsRefused(): void
    {
        $this->signedIn(Role::Member);

        foreach (['/taxes-and-contributions', '/taxes-and-contributions?year=2027', '/taxes-and-contributions/steps/2026/actual/social'] as $page) {
            $this->client->request('GET', $page);
            self::assertResponseStatusCodeSame(403, $page);
        }
        $crawler = $this->client->request('GET', '/cash-flow');
        self::assertResponseIsSuccessful();
        self::assertCount(0, $crawler->filter('a[href^="/taxes-and-contributions"]'));
    }

    private function signedIn(Role $role): void
    {
        $this->browser->seed(role: $role);
        $this->browser->signIn();
        $em = $this->browser->em();
        $em->persist(new LegalEntity('Example Sole Trader', new RegistrationNumber('CZ', '12345695'), null, new Address('Example Street 1', 'Example City', '100 00', 'CZ'), VatStatus::Registered, ''));
        $em->flush();
    }

    private function planned(string $direction, string $category, string $amount, string $on): void
    {
        $user = $this->browser->em()->getRepository(User::class)->findOneBy([]);
        self::assertInstanceOf(User::class, $user);
        self::getContainer()->get(TokenStorageInterface::class)->setToken(new UsernamePasswordToken(new SecurityUser($user->id(), $user->email(), 'hash'), 'main', ['ROLE_USER']));
        self::getContainer()->get(PlannedItemWrites::class)->create(new CreatePlannedItemInput($direction, 'Planned '.$category, $category, $amount, $on));
    }

    private function text(Crawler $crawler, string $selector): string
    {
        return (string) preg_replace('/[ \t\r\n]+/', ' ', trim($crawler->filter($selector)->text()));
    }

    private function dialogHeader(): string
    {
        return 'HTTP_'.str_replace('-', '_', strtoupper(DialogRequest::HEADER));
    }
}
