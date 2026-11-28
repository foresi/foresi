<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\TaxesAndContributions\Integration\CashFlow;

use Foresi\CashFlow\Application\CreatePlannedItemInput;
use Foresi\CashFlow\Application\PlannedItemWrites;
use Foresi\Identity\Domain\Role;
use Foresi\Identity\Domain\User;
use Foresi\Identity\Infrastructure\Security\SecurityUser;
use Foresi\Party\Domain\Address;
use Foresi\Party\Domain\LegalEntity;
use Foresi\Party\Domain\RegistrationNumber;
use Foresi\Party\Domain\VatStatus;
use Foresi\Tests\Identity\Support\SignedInBrowser;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Clock\Clock;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Clock\NativeClock;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;

/**
 * The page Money → Taxes and contributions with Cash flow: the year end with
 * the planned items of the forecast, the planned items that may count a levy
 * twice, and a member who finds no way to the page from the cash flow.
 */
final class TaxesPageTest extends WebTestCase
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
        self::assertCount(1, $crawler->filter('#sidebar [data-nav-sections] a[href="/taxes-and-contributions"]'));
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
        self::assertSame("10,000\u{a0}CZK – show how the year end is computed", $this->text($crawler, '[data-taxes-year-end-cell="income"] a'));
        self::assertSame("0\u{a0}CZK – show how the year end is computed", $this->text($crawler, '[data-taxes-year-end-cell="costs"] a'));
        self::assertSame("0\u{a0}CZK – show how it is computed", $this->text($crawler, '[data-taxes-row="income"] td a'));
        self::assertSame('/taxes-and-contributions/steps/2026/actual/all?part=year_end', $crawler->filter('[data-taxes-year-end-cell="take_home"] a')->attr('href'));
        // Under it, the forecast part by certainty, with what it could not split.
        self::assertSame(['document', 'schedule', 'plan'], $crawler->filter('[data-taxes-forecast-row]')->each(static fn (Crawler $row): string => (string) $row->attr('data-taxes-forecast-row')));
        self::assertSame("Planned items Income 10,000\u{a0}CZK Costs 0\u{a0}CZK", $this->text($crawler, '[data-taxes-forecast-row="plan"]'));
        self::assertSame("Open documents Income 0\u{a0}CZK Costs 0\u{a0}CZK", $this->text($crawler, '[data-taxes-forecast-row="document"]'));
        self::assertSame('recurring invoices and planned items count as entered, with no VAT split', $this->text($crawler, '[data-taxes-forecast-notes]'));
        // No income yet, main activity assumed January to November: eleven months of the minimum base 17,139 at 29.2 %.
        $social = $crawler->filter('[data-taxes-row="social"] a[data-sheet]');
        self::assertSame('/taxes-and-contributions/steps/2026/actual/social', $social->attr('href'));
        self::assertSame("55,051\u{a0}CZK – show how it is computed", $this->text($crawler, '[data-taxes-row="social"] a[data-sheet]'));
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
        self::assertStringContainsString("Taxpayer credit Every taxpayer: 30,840\u{a0}CZK a year. 30,840\u{a0}CZK Claimed", $this->text($crawler, '[data-taxes-credit="taxpayer"]'));
        self::assertStringContainsString('Not claimed', $this->text($crawler, '[data-taxes-credit="disability"]'));
        self::assertStringContainsString("from 100\u{a0}CZK when the income reaches 134,400\u{a0}CZK", $this->text($crawler, '[data-taxes-credit="child_benefit"]'));

        self::assertStringContainsString('activity is assumed', $this->text($crawler, '[data-taxes-warnings]'));
        self::assertStringContainsString('An estimate, not a basis for the tax return.', $this->text($crawler, '[data-taxes-footer]'));
        self::assertSame('The rules of 2026: published by the authorities.', $this->text($crawler, '[data-taxes-rules]'));
        self::assertStringContainsString('the profit as the base of the insurance', $this->text($crawler, '[data-taxes-unverified]'));
        self::assertStringContainsString('carrying a tax loss forward', $this->text($crawler, '[data-taxes-gaps]'));
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

    public function testAMemberFindsNoWayToThePageFromTheCashFlow(): void
    {
        $this->signedIn(Role::Member);

        $crawler = $this->client->request('GET', '/cash-flow');
        self::assertResponseIsSuccessful();
        self::assertCount(0, $crawler->filter('a[href^="/taxes-and-contributions"]'));
    }

    private function signedIn(Role $role): void
    {
        $this->browser->seed(role: $role);
        $this->browser->signIn();
        $em = $this->browser->em();
        $em->persist(new LegalEntity('Example Sole Trader', new RegistrationNumber('CZ', '12345695'), null, new Address('Example Street 1', 'Example City', '100 00', 'CZ'), VatStatus::NotRegistered, ''));
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
}
