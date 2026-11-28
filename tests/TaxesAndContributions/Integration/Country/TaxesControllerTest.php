<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\TaxesAndContributions\Integration\Country;

use Foresi\Identity\Domain\Role;
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
use Foresi\TaxesAndContributions\Contract\OpenAmount;
use Foresi\TaxesAndContributions\Contract\Subject;
use Foresi\Tests\Identity\Support\SignedInBrowser;
use Foresi\Tests\TaxesAndContributions\Support\DatedAmountSource;
use Foresi\Ui\Contract\Dialog\DialogRequest;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Clock\Clock;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Clock\NativeClock;
use Symfony\Component\DomCrawler\Crawler;

/**
 * The page Money → Taxes and contributions by the Czech rules: the steps of
 * the year end and of social insurance in the sheet, a year without rules,
 * a flat-rate year, a method switch and the method chosen in the profile.
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

        $crawler = $this->client->request('GET', '/taxes-and-contributions/steps/2027/actual/social?part=year_end', server: [$this->dialogHeader() => DialogRequest::SHEET]);
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('Social insurance at the year end · Actual costs', $crawler->filter('dialog#sheet-page')->text());
    }

    public function testNextYearAndAYearWithoutRulesSayWhy(): void
    {
        $this->signedIn(Role::Owner);

        $crawler = $this->client->request('GET', '/taxes-and-contributions?year=2027');
        self::assertResponseIsSuccessful();
        self::assertSame('2027', trim($crawler->filter('[data-taxes-years] a[aria-current="page"]')->text()));
        // Next year has nothing to date: the column says why, and the year end is the forecast of all its months.
        self::assertCount(0, $crawler->filter('[data-taxes-empty]'));
        self::assertStringContainsString('The year 2027 has not started yet', $this->text($crawler, '[data-taxes-summary] [data-taxes-to-date-empty="year_not_started"]'));
        self::assertCount(1, $crawler->filter('[data-taxes-year-end-cell="levies"] a[data-sheet]'));
        self::assertCount(1, $crawler->filter('[data-taxes-forecast-badge]'));
        self::assertCount(0, $crawler->filter('[data-taxes-comparison], [data-taxes-credits]'));
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
        self::assertSame("55,051\u{a0}CZK", $this->text($step, '[data-taxes-step-result]'));
        $input = $this->text($step, '[data-taxes-step-input]');
        self::assertStringContainsString('Months of main activity 11', $input);
        self::assertStringContainsString("Minimum base 188,529\u{a0}CZK", $input);
        self::assertStringContainsString("Rate 29.2\u{a0}%", $input);

        // The income tax opens the steps it is made of; the take-home the whole calculation.
        $crawler = $this->client->request('GET', '/taxes-and-contributions/steps/2026/lump_60/income_tax', server: [$this->dialogHeader() => DialogRequest::SHEET]);
        self::assertSame(['tax_base', 'income_tax', 'credits', 'child_benefit'], $crawler->filter('[data-taxes-step]')->each(static fn (Crawler $item): string => (string) $item->attr('data-taxes-step')));
        self::assertSame("15\u{a0}% of the base up to 1,762,812\u{a0}CZK, 23\u{a0}% of the part above; rounded up.", $this->text($crawler, '[data-taxes-step="income_tax"] [data-taxes-step-rule]'));
        $crawler = $this->client->request('GET', '/taxes-and-contributions/steps/2026/lump_60/profit', server: [$this->dialogHeader() => DialogRequest::SHEET]);
        self::assertStringContainsString("Income less 60\u{a0}% of it as the lump sum, at most 1,200,000\u{a0}CZK", $this->text($crawler, '[data-taxes-step-rule]'));
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
        self::assertStringContainsString("184,195\u{a0}CZK", $row->text(), 'Eleven months of 16,745.');
        self::assertSame('/taxes-and-contributions/steps/2026/actual/flat_rate', $row->filter('td a')->attr('href'));
        self::assertStringContainsString('Flat-rate tax – advance 12/2026', $crawler->filter('[data-taxes-payment="flat_rate.advance.2026-12"]')->text());
        self::assertCount(0, $crawler->filter('[data-taxes-credits]'), 'No credit applies.');

        $crawler = $this->client->request('GET', '/taxes-and-contributions/steps/2026/actual/flat_rate', server: [$this->dialogHeader() => DialogRequest::SHEET]);
        self::assertResponseIsSuccessful();
        $step = $crawler->filter('[data-taxes-step="flat_rate"]');
        self::assertStringContainsString('replaces social, health and income tax', $this->text($step, '[data-taxes-step-rule]'));
        self::assertStringContainsString('Months in band 2 11', $this->text($step, '[data-taxes-step-input]'));
        self::assertSame("184,195\u{a0}CZK", $this->text($step, '[data-taxes-step-result]'));
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
        $open = new OpenAmount('invoicing.payments', new Subject('invoice', 'open', null), AmountKind::Income, Money::ofMinor(50_000_00, Currency::of('CZK')), Money::ofMinor(50_000_00, Currency::of('CZK')), Money::ofMinor(0, Currency::of('CZK')));
        self::getContainer()->set(IssuedPaymentAmounts::class, new DatedAmountSource('invoicing.payments', [], ['2026-12-31' => [$open]]));

        $crawler = $this->client->request('GET', '/taxes-and-contributions');
        self::assertResponseIsSuccessful();
        $alert = $this->text($crawler, '[data-taxes-switch]');
        self::assertStringContainsString('The expense method changes from Actual costs to Lump sum 60%, so 2026 gets an additional return', $alert);
        self::assertStringContainsString("its tax base changes by 50,000\u{a0}CZK, the receivables of 50,000\u{a0}CZK open on", $alert);
        self::assertStringContainsString("Its tax of 7,500\u{a0}CZK is due by", $alert);
        self::assertStringNotContainsString('tax base is adjusted with an additional return', $this->text($crawler, '[data-taxes-warnings]'), 'Said once, with the amount.');
        self::assertStringContainsString("Income tax – method change, additional return 2027 7,500\u{a0}CZK", $this->text($crawler, '[data-taxes-payment="income_tax.switch_adjustment.2027"]'));
        self::assertStringContainsString("the adjustment of last year's base for the change of the expense method", $this->text($crawler, '[data-taxes-unverified]'));
        self::assertNotContains('switch_adjustment', $crawler->filter('[data-taxes-row]')->each(static fn (Crawler $row): string => (string) $row->attr('data-taxes-row')), 'Never a row of the totals.');

        $crawler = $this->client->request('GET', '/taxes-and-contributions?year=2026');
        self::assertCount(0, $crawler->filter('[data-taxes-switch]'), 'The year adjusted shows nothing of it.');
    }

    private function signedIn(Role $role): void
    {
        $this->browser->seed(role: $role);
        $this->browser->signIn();
        $em = $this->browser->em();
        $em->persist(new LegalEntity('Example Sole Trader', new RegistrationNumber('CZ', '12345695'), null, new Address('Example Street 1', 'Example City', '100 00', 'CZ'), VatStatus::NotRegistered, ''));
        $em->flush();
    }

    private function text(Crawler $crawler, string $selector): string
    {
        return (string) preg_replace('/[ \t\r\n]+/', ' ', trim($crawler->filter($selector)->text()));
    }

    private function dialogHeader(): string
    {
        return 'HTTP_'.str_replace('-', '_', strtoupper(DialogRequest::HEADER));
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
}
