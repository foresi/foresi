<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\Margin\Infrastructure\Screen;

use Foresi\Identity\Domain\Membership;
use Foresi\Identity\Domain\Role;
use Foresi\Margin\Domain\CostShare;
use Foresi\Margin\Domain\CostShareSource;
use Foresi\Margin\Infrastructure\Navigation\MarginNavigation;
use Foresi\Party\Domain\Address;
use Foresi\Party\Domain\LegalEntity;
use Foresi\Party\Domain\Party;
use Foresi\Party\Domain\PartyKind;
use Foresi\Party\Domain\RegistrationNumber;
use Foresi\Party\Domain\VatStatus;
use Foresi\Tests\Identity\Support\SignedInBrowser;
use Foresi\Tests\Instance\Support\InstanceZone;
use Foresi\Ui\Contract\Navigation\CoreNavigationAreas;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Clock\Clock;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Clock\NativeClock;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\Uid\Uuid;

/**
 * The Margin page, its range dialog and the sheet over a cell, as a staff user
 * sees them and a guest does not. The table is the one MarginTable builds, so
 * only what the screen adds is tested here: the rows in order, the badge of the
 * unassigned row, the links of the cells, the range and who may see it. Today
 * is 10 December 2026.
 */
final class MarginPageTest extends WebTestCase
{
    private const string UNCLAIMED_ONE = '00000000-0000-7000-8000-0000000000d1';
    private const string UNCLAIMED_TWO = '00000000-0000-7000-8000-0000000000d2';
    private const string CLIENT_COST = '00000000-0000-7000-8000-0000000000d3';

    private KernelBrowser $client;
    private SignedInBrowser $browser;
    private Party $clientA;

    protected function setUp(): void
    {
        Clock::set(new MockClock('2026-12-10 09:00:00', 'UTC'));
        $this->client = self::createClient();
        $this->client->disableReboot();
        InstanceZone::set(self::getContainer());
        $this->browser = new SignedInBrowser($this->client, self::getContainer()->get('doctrine.orm.entity_manager'));
    }

    protected function tearDown(): void
    {
        Clock::set(new NativeClock());
        parent::tearDown();
    }

    public function testTheRowsComeInOrderAndUnassignedIsLastWithItsBadge(): void
    {
        $this->start(Role::Owner);
        $this->share(self::CLIENT_COST, '2026-11-01', 'client', 72_996, CostShareSource::Line);
        $this->share(self::UNCLAIMED_ONE, '2026-11-01', null, 10_000, CostShareSource::Unassigned);
        $this->share(self::UNCLAIMED_TWO, '2026-12-01', null, 20_000, CostShareSource::Unassigned);
        $this->share('00000000-0000-7000-8000-0000000000d4', '2026-12-01', 'own', 51_744, CostShareSource::Supplier);

        $page = $this->client->request('GET', '/margin');

        self::assertResponseIsSuccessful();
        self::assertSame(['Margin'], $page->filter('main h1')->each(static fn (Crawler $title): string => trim($title->text())));
        self::assertSame(['2026-10', '2026-11', '2026-12'], $page->filter('[data-margin-table] th[data-month]')->each(static fn (Crawler $month): string => (string) $month->attr('data-month')));
        self::assertSame(['client:'.$this->clientA->id(), 'own', 'unassigned'], $page->filter('[data-margin-table] tbody[data-row]:not([data-row="total"])')->each(static fn (Crawler $row): string => (string) $row->attr('data-row')));
        $unassigned = $page->filter('tbody[data-row="unassigned"]');
        self::assertSame('2 documents', trim($unassigned->filter('[data-margin-unassigned-badge]')->text()));
        self::assertSame(['costs'], $page->filter('tbody[data-row="own"] tr[data-line]')->each(static fn (Crawler $line): string => (string) $line->attr('data-line')));
        self::assertSame(['revenue', 'costs', 'margin'], $page->filter('tbody[data-row="client:'.$this->clientA->id().'"] tr[data-line]')->each(static fn (Crawler $line): string => (string) $line->attr('data-line')));
        self::assertSame('Client A s.r.o.', trim($page->filter('tbody[data-row="client:'.$this->clientA->id().'"] th[colspan]')->text()));
        // The costs of the client in November link to the sheet over that cell; the empty months do not.
        $cells = $page->filter('tbody[data-row="client:'.$this->clientA->id().'"] tr[data-line="costs"] td')->each(static fn (Crawler $cell): ?string => $cell->filter('a[data-sheet]')->count() > 0 ? $cell->filter('a')->attr('href') : null);
        self::assertSame([null, '/margin/cell?row=client:'.$this->clientA->id().'&month=2026-11', null, null], $cells);
        // The table adds every share up: 729.96 + 100 + 200 + 517.44.
        self::assertSame('1,547.40', str_replace("\u{a0}", ' ', trim($page->filter('tbody[data-row="total"] tr[data-line="costs"] [data-line-total]')->text())));
    }

    public function testUnassignedStaysWhenNothingIsUnassignedAndTheBadgeGoes(): void
    {
        $this->start(Role::Member);
        $this->share(self::CLIENT_COST, '2026-11-01', 'client', 72_996, CostShareSource::Line);

        $page = $this->client->request('GET', '/margin');

        self::assertCount(1, $page->filter('tbody[data-row="unassigned"]'));
        self::assertCount(0, $page->filter('[data-margin-unassigned-badge]'));
    }

    public function testACostNobodyClaimedAloneStillShowsTheTable(): void
    {
        $this->start(Role::Owner);
        $this->share(self::UNCLAIMED_ONE, '2026-12-01', null, 10_000, CostShareSource::Unassigned);

        $page = $this->client->request('GET', '/margin');

        self::assertCount(0, $page->filter('[data-margin-empty]'));
        self::assertCount(1, $page->filter('tbody[data-row="unassigned"]'));
        $link = $page->filter('tbody[data-row="unassigned"] a[data-sheet]');
        self::assertSame('Unassigned, Dec 2026, Costs: 100.00', $link->attr('aria-label'));
        self::assertSame('rowgroup', $page->filter('tbody[data-row="unassigned"] th[colspan]')->attr('scope'));
    }

    public function testNothingInTheRangeIsSaidAndNotATableOfDashes(): void
    {
        $this->start(Role::Owner);

        $page = $this->client->request('GET', '/margin');

        self::assertResponseIsSuccessful();
        self::assertCount(1, $page->filter('[data-margin-empty]'));
        self::assertCount(0, $page->filter('[data-margin-table]'));
    }

    public function testTheRangeInTheAddressIsTheRangeShown(): void
    {
        $this->start(Role::Owner);
        $this->share(self::CLIENT_COST, '2026-05-01', 'client', 72_996, CostShareSource::Line);

        $page = $this->client->request('GET', '/margin', ['from' => '2026-05', 'to' => '2026-06']);

        self::assertSame(['2026-05', '2026-06'], $page->filter('th[data-month]')->each(static fn (Crawler $month): string => (string) $month->attr('data-month')));
        self::assertSame('/margin/range?from=2026-05&to=2026-06', $page->filter('a[data-margin-range-open][data-dialog]')->attr('href'));
    }

    public function testARangeThatCannotBeUsedFallsBackToTheDefaultAndSaysWhy(): void
    {
        $this->start(Role::Owner);
        $this->share(self::CLIENT_COST, '2026-11-01', 'client', 72_996, CostShareSource::Line);

        $this->client->request('GET', '/margin', ['from' => '2026-12', 'to' => '2026-11']);
        self::assertResponseRedirects('/margin', 303);
        $page = $this->client->followRedirect();

        self::assertStringContainsString('The range ends before it starts.', $page->filter('[role="alert"]')->text());
        self::assertSame(['2026-10', '2026-11', '2026-12'], $page->filter('th[data-month]')->each(static fn (Crawler $month): string => (string) $month->attr('data-month')));
    }

    public function testTheRangeDialogSetsTheMonthsAndRefusesWhatTheTableRefuses(): void
    {
        $this->start(Role::Owner);

        $dialog = $this->client->request('GET', '/margin/range');
        self::assertResponseIsSuccessful();
        self::assertSame(['2026-10', '2026-12'], [$dialog->filter('#margin-range-from')->attr('value'), $dialog->filter('#margin-range-to')->attr('value')]);

        $this->client->submit($dialog->filter('#margin-range-form')->form(['from' => '2026-03', 'to' => '2026-05']));
        self::assertResponseRedirects('/margin?from=2026-03&to=2026-05', 303);

        foreach ([['2026-05', '2026-03', 'The range ends before it starts.'], ['2024-01', '2026-05', 'At most 24 months are shown at once.'], ['March', '2026-05', 'A month is written as YYYY-MM.']] as [$from, $to, $message]) {
            $page = $this->client->submit($dialog->filter('#margin-range-form')->form(['from' => $from, 'to' => $to]));
            self::assertResponseStatusCodeSame(422);
            self::assertStringContainsString($message, $page->filter('[data-margin-range-error]')->text());
            self::assertSame($from, $page->filter('#margin-range-from')->attr('value'), 'What was typed stays.');
        }
    }

    public function testTheSheetListsTheDocumentsBehindACellAndOffersToAssignTheUnclaimedOnes(): void
    {
        $this->start(Role::Owner);
        $this->share(self::CLIENT_COST, '2026-11-01', 'client', 72_996, CostShareSource::Line);
        $this->share(self::UNCLAIMED_ONE, '2026-11-01', null, 10_000, CostShareSource::Unassigned);
        $this->share(self::UNCLAIMED_TWO, '2026-11-01', null, 20_000, CostShareSource::Unassigned);

        $sheet = $this->client->request('GET', '/margin/cell', ['row' => 'unassigned', 'month' => '2026-11'], server: ['HTTP_X_FORESI_DIALOG' => 'sheet']);

        self::assertResponseIsSuccessful();
        self::assertSame([self::UNCLAIMED_TWO, self::UNCLAIMED_ONE], $sheet->filter('[data-margin-item]')->each(static fn (Crawler $item): string => (string) $item->attr('data-document')));
        self::assertSame(['/margin/documents/'.self::UNCLAIMED_TWO.'/assign', '/margin/documents/'.self::UNCLAIMED_ONE.'/assign'], $sheet->filter('a[data-margin-assign][data-dialog]')->each(static fn (Crawler $link): string => (string) $link->attr('href')));
        self::assertCount(0, $sheet->filter('[data-margin-section="revenue"]'), 'Unassigned has costs only.');
        self::assertStringContainsString('300.00', $sheet->filter('[data-margin-total="costs"]')->text());

        $client = $this->client->request('GET', '/margin/cell', ['row' => 'client:'.$this->clientA->id(), 'month' => '2026-11'], server: ['HTTP_X_FORESI_DIALOG' => 'sheet']);
        self::assertSame(['revenue', 'costs'], $client->filter('[data-margin-section]')->each(static fn (Crawler $section): string => (string) $section->attr('data-margin-section')));
        self::assertCount(1, $client->filter('[data-margin-item]'));
        self::assertCount(0, $client->filter('a[data-margin-assign]'), 'Only an unassigned document is offered for assignment.');
        self::assertCount(1, $client->filter('[data-margin-section="revenue"] [data-margin-section-empty]'));
    }

    public function testACellThatCannotBeNamedIsNotFound(): void
    {
        $this->start(Role::Owner);

        $this->client->request('GET', '/margin/cell', ['row' => 'somebody', 'month' => '2026-11']);
        self::assertResponseStatusCodeSame(404);
        $this->client->request('GET', '/margin/cell', ['row' => 'own', 'month' => 'November']);
        self::assertResponseStatusCodeSame(404);
    }

    public function testAGuestIsRefusedEverywhereAndSeesNoNavigationItem(): void
    {
        $this->start(Role::Owner);
        $membership = $this->browser->em()->getRepository(Membership::class)->findOneBy([]);
        self::assertInstanceOf(Membership::class, $membership);
        $membership->changeRole(Role::Guest, $this->browser->em()->find(Party::class, $this->clientA->id()));
        $this->browser->em()->flush();

        foreach (['/margin', '/margin/cell?row=own&month=2026-11', '/margin/range'] as $url) {
            $this->client->request('GET', $url);
            self::assertResponseStatusCodeSame(403, $url);
        }
        $this->client->request('POST', '/margin/range', ['from' => '2026-01', 'to' => '2026-02', '_token' => 'x']);
        self::assertResponseStatusCodeSame(403);
        self::assertSame([], [...self::getContainer()->get(MarginNavigation::class)->items()]);
    }

    public function testTheStaffSeeTheItemUnderMoney(): void
    {
        $this->start(Role::Member);

        $items = [...self::getContainer()->get(MarginNavigation::class)->items()];

        self::assertCount(1, $items);
        self::assertSame([CoreNavigationAreas::MONEY, 'nav.item.margin', 'app_margin'], [$items[0]->area, $items[0]->labelKey, $items[0]->route]);
    }

    private function share(string $document, string $month, ?string $recipient, int $minor, CostShareSource $source): void
    {
        $em = $this->browser->em();
        $em->persist(new CostShare(Uuid::v7()->toRfc4122(), $document, 'rate:21', new \DateTimeImmutable($month), 'client' === $recipient ? $this->clientA->id() : $recipient, $minor, $source));
        $em->flush();
    }

    private function start(Role $role): void
    {
        $this->browser->seed('en', $role);
        $em = $this->browser->em();
        $em->persist(new LegalEntity('Example Firm s.r.o.', new RegistrationNumber('CZ', '12345695'), null, new Address('Example Street 1', 'Example City', '100 00', 'CZ'), VatStatus::NotRegistered, ''));
        $this->clientA = new Party(PartyKind::Company, 'Client A s.r.o.', new RegistrationNumber('CZ', '27082440'), null, '');
        $em->persist($this->clientA);
        $em->flush();
        $this->browser->signIn();
    }
}
