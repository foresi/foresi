<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\CashFlow\Infrastructure;

use Foresi\CashFlow\Application\CreatePlannedItemInput;
use Foresi\CashFlow\Application\PlannedItemWrites;
use Foresi\ExchangeRate\Contract\RateSource;
use Foresi\Identity\Domain\Role;
use Foresi\Identity\Domain\User;
use Foresi\Identity\Infrastructure\Security\SecurityUser;
use Foresi\Party\Domain\Address;
use Foresi\Party\Domain\LegalEntity;
use Foresi\Party\Domain\RegistrationNumber;
use Foresi\Party\Domain\VatStatus;
use Foresi\Shared\Application\View\Condition;
use Foresi\Shared\Application\View\ViewFilter;
use Foresi\Shared\Application\View\WorkingFilter;
use Foresi\Tests\ExchangeRate\Support\FakeRateSource;
use Foresi\Tests\Identity\Support\SignedInBrowser;
use Foresi\Tests\Party\Support\FirmAccounts;
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
 * The Cash flow page: the first run with an account and no balance, entering a
 * balance and the minimum balance in their dialogs, the twelve months of the
 * table, and a cell's items in the sheet.
 */
final class CashFlowControllerTest extends WebTestCase
{
    private const string IBAN = 'CZ6508000000192000145399';

    private KernelBrowser $client;
    private SignedInBrowser $browser;
    private User $user;
    private string $account;

    protected function setUp(): void
    {
        Clock::set(new MockClock('2026-11-19 10:00:00', 'UTC'));
        $this->client = self::createClient();
        $this->client->disableReboot();
        $rates = new FakeRateSource();
        $rates->publish('2026-11-18', '24.500', '21.000');
        self::getContainer()->set(RateSource::class, $rates);
        $this->browser = new SignedInBrowser($this->client, self::getContainer()->get('doctrine.orm.entity_manager'));
    }

    protected function tearDown(): void
    {
        Clock::set(new NativeClock());
        parent::tearDown();
    }

    public function testAFirmWithoutAnAccountIsAskedForOne(): void
    {
        $this->signedIn(withAccount: false);

        $this->client->request('GET', '/cash-flow');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('[data-cash-flow-empty]', 'Add a bank account');
        self::assertSelectorNotExists('[data-cash-flow-alert]');
    }

    public function testAnAccountWithoutABalanceAsksForOne(): void
    {
        $this->signedIn();

        $crawler = $this->client->request('GET', '/cash-flow');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('[data-cash-flow-alert]', 'The forecast is incomplete');
        self::assertSelectorTextContains('[data-cash-flow-alert]', 'CZ65 0800 0000 1920 0014 5399 has no balance yet');
        self::assertSame('/cash-flow/balance?account='.$this->account, $crawler->filter('[data-cash-flow-empty] a[data-dialog]')->attr('href'));
        self::assertCount(1, $crawler->filter('[data-cash-flow-empty]'));
        self::assertCount(1, $crawler->filter('header [data-cash-flow-balance][data-dialog]'));
        self::assertCount(1, $crawler->filter('header [data-cash-flow-minimum][data-dialog]'));
        self::assertCount(1, $crawler->filter('nav a[href="/cash-flow"]'));
        // Without JavaScript each dialog is a page of its own.
        foreach (['/cash-flow/balance', '/cash-flow/minimum-balance'] as $page) {
            $this->client->request('GET', $page);
            self::assertResponseIsSuccessful();
        }
    }

    public function testAnEnteredBalanceGivesTheFiguresAndTwelveMonths(): void
    {
        $this->signedIn();
        $values = $this->dialogForm('/cash-flow/balance', '#cash-flow-balance-form');
        self::assertSame($this->account, $values['account']);
        self::assertSame('2026-11-19', $values['on']);

        $this->dialogPost('/cash-flow/balance', ['amount' => '125000.50'] + $values);
        self::assertSame('{"location":"\\/cash-flow"}', $this->client->getResponse()->getContent());
        $crawler = $this->client->request('GET', '/cash-flow');

        self::assertSelectorNotExists('[data-cash-flow-alert]');
        self::assertSelectorNotExists('[data-cash-flow-empty]');
        $kpis = $crawler->filter('[data-kpi]');
        self::assertStringContainsString('125,001', $kpis->eq(0)->text());
        self::assertStringContainsString('Entered balance of Nov 19', $kpis->eq(0)->text());
        self::assertStringContainsString('Not set; set it under', $kpis->eq(2)->text());
        // Twelve calendar months and no week in front of them; the first starts today.
        $periods = $crawler->filter('[data-cash-flow-table] th[data-period]');
        self::assertCount(12, $periods);
        self::assertSame('2026-11', $periods->eq(0)->attr('data-period'));
        // Two lines in every column: the month alone, then the days of a partial month or the year.
        self::assertSame(['November', 'Nov 19–30'], $periods->eq(0)->filter('span')->each(static fn ($line): string => $line->text()));
        self::assertSame(['December', '2026'], $periods->eq(1)->filter('span')->each(static fn ($line): string => $line->text()));
        self::assertSame(['January', '2027'], $periods->eq(2)->filter('span')->each(static fn ($line): string => $line->text()));
        self::assertSame('2027-10', $periods->eq(11)->attr('data-period'));
    }

    public function testThreeMonthsAreThirteenWeeksForTheChartAndTheTable(): void
    {
        $this->signedIn();
        $this->dialogPost('/cash-flow/balance', ['amount' => '60000'] + $this->dialogForm('/cash-flow/balance', '#cash-flow-balance-form'));
        $this->plan('2026-12-15', '20000.00');
        // After the thirteenth week: the months view has it, the weeks view does not.
        $this->plan('2027-03-10', '90000.00');

        $crawler = $this->client->request('GET', '/cash-flow?view=weeks');

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('3 months', $crawler->filter('[data-cash-flow-views] a[aria-current="page"]')->text());
        $periods = $crawler->filter('[data-cash-flow-table] th[data-period]');
        self::assertCount(13, $periods);
        self::assertSame('2026-W47', $periods->eq(0)->attr('data-period'));
        self::assertSame(['Week 47', 'Nov 19–22'], $periods->eq(0)->filter('span')->each(static fn ($line): string => $line->text()));
        self::assertSame(['Week 49', 'Nov 30 – Dec 6'], $periods->eq(2)->filter('span')->each(static fn ($line): string => $line->text()));
        self::assertSame('2027-W06', $periods->eq(12)->attr('data-period'));
        self::assertCount(13, $crawler->filter('[data-cash-flow-chart] [data-balance-chart-target="period"]'));
        self::assertSame('Money in and out and the closing balance of each week; the table below has the figures.', $crawler->filter('[data-cash-flow-chart] svg')->attr('aria-label'));
        // The lowest point and the end are those of the thirteen weeks: the March rent is past them.
        $kpis = $crawler->filter('[data-kpi]');
        self::assertStringContainsString('40,000', $kpis->eq(1)->text());
        self::assertStringContainsString('on Dec 15', $kpis->eq(1)->text());
        self::assertStringContainsString('Feb 14', $kpis->eq(3)->text());
        // A week's cell opens its items in the sheet.
        $link = $crawler->filter('[data-cash-flow-table] tr[data-row="category"] a[data-sheet]');
        self::assertCount(1, $link);
        self::assertSame('/cash-flow/items?period=2026-W51&direction=out&category=rent', $link->attr('href'));
        $this->client->request('GET', $link->attr('href'));
        self::assertResponseIsSuccessful();

        $months = $this->client->request('GET', '/cash-flow');
        self::assertCount(12, $months->filter('[data-cash-flow-table] th[data-period]'));
        self::assertCount(12, $months->filter('[data-cash-flow-chart] [data-balance-chart-target="period"]'));
        self::assertStringContainsString('-50,000', $months->filter('[data-kpi]')->eq(1)->text());
    }

    public function testAFutureBalanceIsRefusedInTheDialog(): void
    {
        $this->signedIn();
        $values = $this->dialogForm('/cash-flow/balance', '#cash-flow-balance-form');

        $crawler = $this->dialogPost('/cash-flow/balance', ['amount' => '100', 'on' => '2026-11-20'] + $values);

        self::assertResponseStatusCodeSame(422);
        self::assertStringContainsString('A balance is entered for today or a day before.', $crawler->filter('#cash-flow-balance-form')->text());
    }

    public function testAClosingBelowTheMinimumIsMarkedAndDated(): void
    {
        $this->signedIn();
        $this->dialogPost('/cash-flow/balance', ['amount' => '60000'] + $this->dialogForm('/cash-flow/balance', '#cash-flow-balance-form'));
        $this->plan('2026-12-15', '20000.00');
        $this->dialogPost('/cash-flow/minimum-balance', ['amount' => '50000'] + $this->dialogForm('/cash-flow/minimum-balance', '#cash-flow-minimum-form'));
        self::assertSame('{"location":"\\/cash-flow"}', $this->client->getResponse()->getContent());

        $crawler = $this->client->request('GET', '/cash-flow');

        self::assertStringContainsString('Below on Dec 15', $crawler->filter('[data-kpi]')->eq(2)->text());
        $closing = $crawler->filter('[data-cash-flow-table] tr[data-row="closing"] td');
        self::assertNull($closing->eq(0)->attr('data-below-minimum'));
        self::assertNotNull($closing->eq(1)->attr('data-below-minimum'));
    }

    public function testACellOpensItsItemsInTheSheet(): void
    {
        $this->signedIn();
        $this->dialogPost('/cash-flow/balance', ['amount' => '60000'] + $this->dialogForm('/cash-flow/balance', '#cash-flow-balance-form'));
        $this->plan('2026-12-15', '20000.00');
        $crawler = $this->client->request('GET', '/cash-flow');
        $link = $crawler->filter('[data-cash-flow-table] tr[data-row="category"] a[data-sheet]');
        self::assertCount(1, $link);
        self::assertSame('/cash-flow/items?period=2026-12&direction=out&category=rent', $link->attr('href'));

        $sheet = $this->client->request('GET', $link->attr('href'), server: ['HTTP_'.str_replace('-', '_', strtoupper(DialogRequest::HEADER)) => 'sheet']);

        self::assertResponseIsSuccessful();
        self::assertCount(1, $sheet->filter('#sheet-page'));
        self::assertStringContainsString('Money out · Rent · Dec 2026', $sheet->filter('#sheet-page')->text());
        self::assertStringContainsString('Office rent', $sheet->filter('[data-cash-flow-item]')->text());
        self::assertSame('Planned items', $sheet->filter('[data-cash-flow-item] [data-certainty]')->text());
        // A plan's source says no more than its certainty.
        self::assertCount(0, $sheet->filter('[data-cash-flow-item] [data-source]'));
        $this->client->request('GET', $link->attr('href'));
        self::assertResponseIsSuccessful();
    }

    public function testACertaintyFilterNarrowsTheTableAndKeepsTheBalanceOfToday(): void
    {
        $this->signedIn();
        $this->dialogPost('/cash-flow/balance', ['amount' => '60000'] + $this->dialogForm('/cash-flow/balance', '#cash-flow-balance-form'));
        $this->plan('2026-12-15', '20000.00');

        // The builder's rows become the filter in the address, the view kept.
        $this->client->request('GET', '/cash-flow', ['view' => 'weeks', 'conditions' => '1', 'c' => [['field' => 'certainty', 'op' => 'is_any_of', 'values' => ['document', 'estimate']]]]);
        self::assertResponseStatusCodeSame(303);
        $location = (string) $this->client->getResponse()->headers->get('Location');
        self::assertStringStartsWith('/cash-flow?view=weeks&f=', $location);
        $crawler = $this->client->request('GET', $location);

        self::assertResponseIsSuccessful();
        self::assertCount(1, $crawler->filter('[data-cash-flow-table-empty]'));
        self::assertCount(0, $crawler->filter('[data-cash-flow-table]'));
        self::assertStringContainsString('Source: Open documents, Tax, insurance and VAT estimate', $crawler->filter('[data-cash-flow-filter]')->text());
        // The balance today is never filtered.
        self::assertStringContainsString('60,000', $crawler->filter('[data-cash-flow-kpis]')->text());

        $f = WorkingFilter::encode(new ViewFilter([new Condition('certainty', 'is', 'plan')]));
        $crawler = $this->client->request('GET', '/cash-flow', ['f' => $f]);
        $link = $crawler->filter('[data-cash-flow-table] tr[data-row="category"] a[data-sheet]');
        self::assertCount(1, $link);
        self::assertSame('/cash-flow/items?period=2026-12&direction=out&category=rent&f='.$f, $link->attr('href'));
        self::assertStringContainsString('f='.$f, (string) $crawler->filter('[data-cash-flow-views] a')->first()->attr('href'));
        $sheet = $this->client->request('GET', $link->attr('href'));
        self::assertStringContainsString('Office rent', $sheet->filter('[data-cash-flow-item]')->text());

        // A filter that leaves the plan out leaves its items out of the sheet too.
        $none = WorkingFilter::encode(new ViewFilter([new Condition('certainty', 'is_not', 'plan')]));
        $sheet = $this->client->request('GET', '/cash-flow/items?period=2026-12&direction=out&category=rent&f='.$none);
        self::assertResponseIsSuccessful();
        self::assertCount(0, $sheet->filter('[data-cash-flow-item]'));
    }

    public function testAGuestMayNotSeeTheCashFlow(): void
    {
        $this->browser->seed(role: Role::Guest);
        $this->browser->signIn();

        $this->client->request('GET', '/cash-flow');

        self::assertResponseStatusCodeSame(403);
    }

    private function signedIn(bool $withAccount = true): void
    {
        $this->user = $this->browser->seed();
        $this->browser->signIn();
        $em = $this->browser->em();
        $firm = new LegalEntity('Example Firm s.r.o.', new RegistrationNumber('CZ', '12345695'), null, new Address('Example Street 1', 'Example City', '100 00', 'CZ'), VatStatus::NotRegistered, '');
        $em->persist($firm);
        if ($withAccount) {
            $this->account = FirmAccounts::add($em, self::IBAN)->id();
        }
        $em->flush();
    }

    private function plan(string $on, string $amount): void
    {
        self::getContainer()->get(TokenStorageInterface::class)->setToken(new UsernamePasswordToken(new SecurityUser($this->user->id(), $this->user->email(), 'hash'), 'main', ['ROLE_USER']));
        self::getContainer()->get(PlannedItemWrites::class)->create(new CreatePlannedItemInput('out', 'Office rent', 'rent', $amount, $on));
    }

    /**
     * @return array<string, string>
     */
    private function dialogForm(string $url, string $form): array
    {
        $crawler = $this->client->request('GET', $url, server: ['HTTP_'.str_replace('-', '_', strtoupper(DialogRequest::HEADER)) => '1']);
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
        return $this->client->request('POST', $url, $values, server: ['HTTP_'.str_replace('-', '_', strtoupper(DialogRequest::HEADER)) => '1']);
    }
}
