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
use Foresi\Tests\Identity\Support\SignedInBrowser;
use Foresi\Ui\Contract\Dialog\DialogRequest;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Clock\Clock;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Clock\NativeClock;
use Symfony\Component\DomCrawler\Crawler;

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

    public function testTheOwnerSeesWhatTheyKeepToDateBesideTheEmptyYearEnd(): void
    {
        $this->signedIn(Role::Owner);

        $crawler = $this->client->request('GET', '/taxes-and-contributions');
        self::assertResponseIsSuccessful();
        self::assertCount(1, $crawler->filter('#sidebar a[href="/taxes-and-contributions"]'));
        self::assertCount(1, $crawler->filter('header a[data-taxes-profile][href="/taxes-and-contributions/profile"]'));
        self::assertSame(['2025', '2026', '2027'], $crawler->filter('[data-taxes-years] a')->each(static fn (Crawler $tab): string => trim($tab->text())));
        self::assertSame('2026', trim($crawler->filter('[data-taxes-years] a[aria-current="page"]')->text()));
        self::assertSame('/taxes-and-contributions?year=2027', $crawler->filter('[data-taxes-years] a')->eq(2)->attr('href'));

        // One table: the rows to date, and the year end apart with the reason it is empty.
        self::assertSelectorTextContains('[data-taxes-summary] h2', 'What you keep');
        self::assertSame('Method: Actual costs (none in the profile, the cheaper one shown)', $this->text($crawler, '[data-taxes-method]'));
        self::assertSame(['income', 'costs', 'social', 'health', 'sickness', 'income_tax', 'child_bonus', 'levies', 'take_home'], $crawler->filter('[data-taxes-row]')->each(static fn (Crawler $row): string => (string) $row->attr('data-taxes-row')));
        self::assertSame('To date', $this->text($crawler, '[data-taxes-to-date]'));
        self::assertSame('At year end', $this->text($crawler, '[data-taxes-year-end]'));
        $yearEnd = $crawler->filter('[data-taxes-year-end-empty="forecast_not_built"]');
        self::assertSame('9', $yearEnd->attr('rowspan'));
        self::assertStringContainsString('Forecast The year end comes with the forecast of Cash flow.', $this->text($crawler, '[data-taxes-year-end-empty]'));
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
        self::assertCount(8, $crawler->filter('[data-taxes-step]'));
        self::assertSame('Income less the costs actually paid, both on the cash basis.', $this->text($crawler, '[data-taxes-step="profit"] [data-taxes-step-rule]'));

        // Without the sheet, the same URL is a page.
        $crawler = $this->client->request('GET', '/taxes-and-contributions/steps/2026/actual/social');
        self::assertResponseIsSuccessful();
        self::assertCount(0, $crawler->filter('dialog#sheet-page'));
        self::assertCount(1, $crawler->filter('nav a[href="/taxes-and-contributions?year=2026"]'));
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

    private function text(Crawler $crawler, string $selector): string
    {
        return (string) preg_replace('/[ \t\r\n]+/', ' ', trim($crawler->filter($selector)->text()));
    }

    private function dialogHeader(): string
    {
        return 'HTTP_'.str_replace('-', '_', strtoupper(DialogRequest::HEADER));
    }
}
