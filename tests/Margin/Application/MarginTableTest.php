<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\Margin\Application;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Foresi\Identity\Domain\Membership;
use Foresi\Identity\Domain\Role;
use Foresi\Identity\Domain\User;
use Foresi\Identity\Infrastructure\Security\SecurityUser;
use Foresi\Instance\Application\InstanceClock;
use Foresi\Margin\Application\CostPairings;
use Foresi\Margin\Application\CostShareCalculation;
use Foresi\Margin\Application\CostShareReads;
use Foresi\Margin\Application\CostSources;
use Foresi\Margin\Application\KnownGaps;
use Foresi\Margin\Application\MappingVersions;
use Foresi\Margin\Application\MarginAccess;
use Foresi\Margin\Application\MarginCell;
use Foresi\Margin\Application\MarginCellView;
use Foresi\Margin\Application\MarginRanges;
use Foresi\Margin\Application\MarginRowView;
use Foresi\Margin\Application\MarginTable;
use Foresi\Margin\Application\MarginTableView;
use Foresi\Margin\Application\OccurrenceFill;
use Foresi\Margin\Application\RevenueSources;
use Foresi\Margin\Contract\CostDocument;
use Foresi\Margin\Contract\CostLine;
use Foresi\Margin\Contract\RevenueDocument;
use Foresi\Margin\Contract\RevenueLine;
use Foresi\Margin\Domain\CostShare;
use Foresi\Margin\Domain\CostShareSource;
use Foresi\Margin\Domain\MarginRefused;
use Foresi\Margin\Domain\ShareSplitter;
use Foresi\Party\Contract\PartyDirectory;
use Foresi\Party\Domain\Party;
use Foresi\Party\Domain\PartyKind;
use Foresi\Shared\Domain\Currency;
use Foresi\Shared\Domain\Money;
use Foresi\Tests\Instance\Support\InstanceZone;
use Foresi\Tests\Margin\Support\FixedCostSource;
use Foresi\Tests\Margin\Support\FixedRevenueSource;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\Clock;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Clock\NativeClock;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Uid\Uuid;

/**
 * The margin table and its cells, on a real database with the cost shares the
 * test stores and the revenue and cost documents it names, so neither
 * Invoicing nor Purchasing is needed. Today is 10 December 2026 in Prague.
 */
final class MarginTableTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private FixedRevenueSource $revenue;
    private FixedCostSource $costSource;
    private MarginTable $table;
    private MarginCell $cell;
    private string $clientA;
    private string $clientB;
    private string $clientC;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = self::getContainer();
        Clock::set(new MockClock('2026-12-10 09:00:00', 'UTC'));
        InstanceZone::set($container);
        $this->em = $container->get('doctrine.orm.entity_manager');
        $tool = new SchemaTool($this->em);
        $metadata = $this->em->getMetadataFactory()->getAllMetadata();
        $tool->dropSchema($metadata);
        $tool->createSchema($metadata);

        $this->clientA = $this->contact('Client A');
        $this->clientB = $this->contact('Client B');
        $this->clientC = $this->contact('Client C');
        $user = new User('owner@example.com', 'hash', 'Olga Owner');
        $this->em->persist($user);
        $this->em->persist(new Membership($user, Role::Owner));
        $this->em->flush();
        $container->get(TokenStorageInterface::class)->setToken(new UsernamePasswordToken(new SecurityUser($user->id(), $user->email(), 'hash'), 'main', ['ROLE_USER']));

        $this->revenue = new FixedRevenueSource();
        $this->costSource = new FixedCostSource();
        $clock = $container->get(InstanceClock::class);
        $costSources = new CostSources([$this->costSource]);
        $revenueSources = new RevenueSources([$this->revenue]);
        $access = $container->get(MarginAccess::class);
        $ranges = new MarginRanges($clock);
        $reads = new CostShareReads($this->em);
        $directory = $container->get(PartyDirectory::class);
        $calculation = new CostShareCalculation($this->em, $costSources, new ShareSplitter(), $clock, new CostPairings(), new MappingVersions($this->em), new OccurrenceFill(new ShareSplitter()));
        $this->table = new MarginTable($access, $ranges, $revenueSources, $reads, $calculation, $costSources, new KnownGaps($costSources), $directory);
        $this->cell = new MarginCell($access, $ranges, $revenueSources, $reads, $costSources, $directory);
    }

    protected function tearDown(): void
    {
        Clock::set(new NativeClock());
        parent::tearDown();
    }

    public function testTheExampleMonth(): void
    {
        $this->invoice('i1', $this->clientA, '2026-11-10', 3_000_000);
        $this->invoice('i2', $this->clientA, '2026-11-28', 981_500);
        // Voice API (22 USD at 23.10 CZK) goes whole to Client A, Cloud Host 30 % of 739.20 to Client A and the rest to the firm.
        $this->share('voice', '2026-11', $this->clientA, 50_820);
        $this->share('cloud', '2026-11', $this->clientA, 22_176);
        $this->share('cloud', '2026-11', 'own', 51_744);

        $view = $this->table->of('2026-11', '2026-11');

        $row = $this->row($view, 'client:'.$this->clientA);
        self::assertSame([3_981_500, 72_996, 3_908_504], [$row->cells['2026-11']->revenue->minor(), $row->cells['2026-11']->costs->minor(), $row->cells['2026-11']->margin->minor()]);
        self::assertSame(['Client A', $this->clientA], [$row->name, $row->clientId]);
        self::assertSame([51_744, -51_744], [$this->row($view, 'own')->total->costs->minor(), $this->row($view, 'own')->total->margin->minor()]);
        self::assertSame([3_981_500, 124_740, 3_856_760], [$view->total->revenue->minor(), $view->total->costs->minor(), $view->total->margin->minor()]);
    }

    public function testTheUnassignedRowHoldsEveryUnmappedLine(): void
    {
        $this->share('d1', '2026-10', null, 10_000);
        $this->share('d2', '2026-11', null, 20_000);
        $this->share('d3', '2026-11', $this->clientA, 30_000);
        $this->share('d4', '2026-11', 'own', 5_000);
        $this->share('d5', '2026-09', null, 99_999);

        $view = $this->table->of('2026-10', '2026-12');

        $unassigned = $this->row($view, 'unassigned');
        self::assertSame([10_000, 20_000, 0], array_map(static fn ($cell): int => $cell->costs->minor(), array_values($unassigned->cells)));
        self::assertSame(65_000, $view->total->costs->minor(), 'The costs of the table are every share in the range, unassigned included.');
        $sum = array_sum(array_map(static fn (CostShare $share): int => $share->month()->format('Y-m') >= '2026-10' ? $share->amount()->minor() : 0, $this->em->getRepository(CostShare::class)->findAll()));
        self::assertSame($sum, $view->total->costs->minor());
        self::assertSame([55_000, 20_000], [$view->monthTotals['2026-11']->costs->minor(), $unassigned->cells['2026-11']->costs->minor()]);
    }

    public function testTheRowsAreOrderedByRevenueThenNameWithOwnUseAndUnassignedLast(): void
    {
        $this->invoice('i1', $this->clientB, '2026-11-02', 100_000);
        $this->invoice('i2', $this->clientC, '2026-11-03', 100_000);
        $this->invoice('i3', $this->clientA, '2026-11-04', 50_000);
        $this->share('d1', '2026-11', $this->contact('Client D'), 1_000);

        $view = $this->table->of('2026-11', '2026-11');

        self::assertSame(
            ['Client B', 'Client C', 'Client A', 'Client D', null, null],
            array_map(static fn (MarginRowView $row): ?string => $row->name, $view->rows),
            'Equal revenue goes by name; a client with costs only comes after the ones with revenue; the last two rows have no name.',
        );
        self::assertSame(['own', 'unassigned'], array_slice(array_map(static fn (MarginRowView $row): string => $row->key, $view->rows), -2));
    }

    public function testOwnUseAndUnassignedShowEvenWithNothingInTheRange(): void
    {
        $view = $this->table->of('2026-11', '2026-12');

        self::assertSame(['own', 'unassigned'], array_map(static fn (MarginRowView $row): string => $row->key, $view->rows));
        self::assertSame(['2026-11', '2026-12'], array_keys($view->rows[1]->cells));
        self::assertTrue($view->total->isEmpty());
        self::assertSame([], $view->knownGaps);
    }

    public function testAClientWithCostsButNoRevenueHasARow(): void
    {
        $this->share('d1', '2026-11', $this->clientB, 12_000);

        $view = $this->table->of('2026-11', '2026-11');

        $row = $this->row($view, 'client:'.$this->clientB);
        self::assertSame([0, 12_000, -12_000], [$row->total->revenue->minor(), $row->total->costs->minor(), $row->total->margin->minor()]);
        self::assertNull($this->findRow($view, 'client:'.$this->clientA), 'A client with neither has no row.');
    }

    public function testAClientThatIsNoLongerThereShowsItsIdAndItsRevenueStillCounts(): void
    {
        $gone = '0197a1de-0000-7000-8000-0000000000aa';
        $this->invoice('i1', $gone, '2026-11-02', 70_000);

        $row = $this->row($this->table->of('2026-11', '2026-11'), 'client:'.$gone);

        self::assertSame([$gone, 70_000], [$row->name, $row->total->revenue->minor()]);
    }

    public function testRevenueFallsInTheMonthOfTheTaxPoint(): void
    {
        $this->invoice('i1', $this->clientA, '2026-11-30', 100_000);
        $this->invoice('i2', $this->clientA, '2026-12-01', 200_000);
        $this->invoice('i3', $this->clientA, '2027-01-01', 400_000);

        $view = $this->table->of('2026-11', '2026-12');

        $cells = $this->row($view, 'client:'.$this->clientA)->cells;
        self::assertSame([100_000, 200_000], [$cells['2026-11']->revenue->minor(), $cells['2026-12']->revenue->minor()]);
    }

    public function testTheDefaultRangeIsTheLastThreeMonthsEvenInTheHourAfterPragueMidnight(): void
    {
        // 23:30 UTC on 30 November is 00:30 on 1 December in Prague: it is December already.
        Clock::set(new MockClock('2026-11-30 23:30:00', 'UTC'));
        $this->invoice('i1', $this->clientA, '2026-11-30', 100_000);
        $this->invoice('i2', $this->clientA, '2026-12-01', 200_000);

        $view = $this->table->of();

        self::assertSame(['2026-10', '2026-11', '2026-12'], $view->months);
        self::assertSame(['2026-10', '2026-12'], [$view->from, $view->to]);
        $cells = $this->row($view, 'client:'.$this->clientA)->cells;
        self::assertSame([0, 100_000, 200_000], array_map(static fn ($cell): int => $cell->revenue->minor(), array_values($cells)));
    }

    public function testWithoutARevenueSourceTheTableHasCostsOnly(): void
    {
        $this->share('d1', '2026-11', $this->clientA, 5_000);
        $table = new MarginTable(self::getContainer()->get(MarginAccess::class), new MarginRanges(self::getContainer()->get(InstanceClock::class)), new RevenueSources([]), new CostShareReads($this->em), $this->calculation(), new CostSources([]), new KnownGaps(new CostSources([])), self::getContainer()->get(PartyDirectory::class));

        $view = $table->of('2026-11', '2026-11');

        self::assertSame([0, 5_000], [$view->total->revenue->minor(), $view->total->costs->minor()]);
    }

    public function testTheMissingExchangeRatesInTheRangeAreCarried(): void
    {
        $this->costSource->documents['rate1'] = $this->costDocument('rate1', '2026-11-12', [new CostLine('line-1', 'Voice API', null)]);
        $this->costSource->documents['rate2'] = $this->costDocument('rate2', '2026-05-12', [new CostLine('line-1', 'Voice API', null)]);
        $this->costSource->documents['fine'] = $this->costDocument('fine', '2026-11-12', [new CostLine('line-1', 'Hosting', Money::ofMinor(100, Currency::CZK))]);

        $view = $this->table->of('2026-11', '2026-11');

        self::assertCount(1, $view->knownGaps);
        self::assertSame(['missing_exchange_rate', ['rate1']], [$view->knownGaps[0]->code, $view->knownGaps[0]->documentIds]);
    }

    public function testTheRangeIsRefusedWhenItIsInvalidReversedOrTooLong(): void
    {
        $this->assertRefused('invalid_month', fn (): MarginTableView => $this->table->of('2026-13', '2026-12'));
        $this->assertRefused('invalid_month', fn (): MarginTableView => $this->table->of('2026-11', '2026-1'));
        $this->assertRefused('invalid_month', fn (): MarginTableView => $this->table->of('November', null));
        $this->assertRefused('range_reversed', fn (): MarginTableView => $this->table->of('2026-12', '2026-11'));
        $this->assertRefused('range_too_long', fn (): MarginTableView => $this->table->of('2024-11', '2026-11'));
        self::assertCount(24, $this->table->of('2025-01', '2026-12')->months);
    }

    public function testAMissingEndOrStartTakesItsDefault(): void
    {
        self::assertSame(['2026-10', '2026-11', '2026-12'], $this->table->of()->months);
        self::assertSame(['2026-09', '2026-10', '2026-11'], $this->table->of(null, '2026-11')->months);
        self::assertSame(['2026-11', '2026-12'], $this->table->of('2026-11')->months);
        $this->assertRefused('range_reversed', fn (): MarginTableView => $this->table->of('2027-01'));
    }

    public function testAGuestIsRefused(): void
    {
        $guest = new User('guest@example.com', 'hash', 'Gil Guest');
        $this->em->persist($guest);
        $this->em->persist(new Membership($guest, Role::Guest, $this->em->find(Party::class, $this->clientA)));
        $this->em->flush();
        self::getContainer()->get(TokenStorageInterface::class)->setToken(new UsernamePasswordToken(new SecurityUser($guest->id(), $guest->email(), 'hash'), 'main', ['ROLE_USER']));

        $this->assertRefused('forbidden', fn (): MarginTableView => $this->table->of('2026-13', 'x'));
        $this->assertRefused('forbidden', fn (): MarginCellView => $this->cell->of('nonsense', '2026-13'));
    }

    public function testACellListsTheDocumentsBehindIt(): void
    {
        $this->invoice('i1', $this->clientA, '2026-11-20', 3_000_000);
        $this->invoice('i2', $this->clientA, '2026-11-02', 981_500);
        $this->invoice('i3', $this->clientB, '2026-11-02', 1_000);
        $this->invoice('i4', $this->clientA, '2026-12-02', 5_000);
        $this->costSource->documents['voice'] = $this->costDocument('voice', '2026-11-05', [new CostLine('line-1', 'Voice API usage', Money::ofMinor(50_820, Currency::CZK))]);
        $this->costSource->documents['cloud'] = $this->costDocument('cloud', '2026-11-06', [new CostLine('line-1', 'Hosting', Money::ofMinor(73_920, Currency::CZK))]);
        $this->share('voice', '2026-11', $this->clientA, 50_820, CostShareSource::Document);
        $this->share('cloud', '2026-11', $this->clientA, 22_176, CostShareSource::Line);
        $this->share('cloud', '2026-11', 'own', 51_744, CostShareSource::Line);
        $this->share('gone', '2026-11', $this->clientA, 100, CostShareSource::Supplier);

        $cell = $this->cell->of('client:'.$this->clientA, '2026-11');

        self::assertSame(['Client A', '2026-11', 3_981_500, 73_096], [$cell->name, $cell->month, $cell->figures->revenue->minor(), $cell->figures->costs->minor()]);
        self::assertSame(3_908_404, $cell->figures->margin->minor());
        self::assertSame([['EN-i2', 981_500, 'revenue', 'invoice', '/invoices/i2'], ['EN-i1', 3_000_000, 'revenue', 'invoice', '/invoices/i1']], array_map(static fn ($item): array => [$item->label, $item->amountCzk->minor(), $item->source, $item->kind, $item->url], $cell->revenue), 'By tax point.');
        self::assertSame([
            ['EN-voice', 'Voice API usage', 50_820, 'document', '/purchasing/voice'],
            ['EN-cloud', 'Hosting', 22_176, 'line', '/purchasing/cloud'],
            ['gone', null, 100, 'supplier', null],
        ], array_map(static fn ($item): array => [$item->label, $item->text, $item->amountCzk->minor(), $item->source, $item->url], $cell->costs));
        self::assertSame(['voice', 'cloud', 'gone'], array_map(static fn ($item): string => $item->documentId, $cell->costs));

        $own = $this->cell->of('own', '2026-11');
        self::assertSame([[], 51_744, -51_744, null], [$own->revenue, $own->figures->costs->minor(), $own->figures->margin->minor(), $own->name]);
    }

    public function testTheUnassignedCellHoldsTheSharesNobodyClaimed(): void
    {
        $this->share('d1', '2026-11', null, 7_000, CostShareSource::Unassigned);
        $this->share('d2', '2026-12', null, 9_000, CostShareSource::Unassigned);

        $cell = $this->cell->of('unassigned', '2026-11');

        self::assertSame([7_000, 'unassigned'], [$cell->figures->costs->minor(), $cell->costs[0]->source]);
        self::assertSame([], $cell->revenue);
    }

    public function testACellOfAnUnknownRowOrMonthIsRefused(): void
    {
        $this->assertRefused('invalid_row', fn (): MarginCellView => $this->cell->of('client:not-a-uuid', '2026-11'));
        $this->assertRefused('invalid_row', fn (): MarginCellView => $this->cell->of('everyone', '2026-11'));
        $this->assertRefused('invalid_month', fn (): MarginCellView => $this->cell->of('own', '2026-11-05'));
    }

    private function row(MarginTableView $view, string $key): MarginRowView
    {
        return $this->findRow($view, $key) ?? self::fail('No row '.$key);
    }

    private function findRow(MarginTableView $view, string $key): ?MarginRowView
    {
        return array_find($view->rows, static fn (MarginRowView $row): bool => $row->key === $key);
    }

    private function contact(string $name): string
    {
        $party = new Party(PartyKind::Person, $name, null, null, '');
        $this->em->persist($party);
        $this->em->flush();

        return $party->id();
    }

    private function invoice(string $id, string $customerId, string $taxPoint, int $minor): void
    {
        $this->revenue->documents[] = new RevenueDocument($id, 'EN-'.$id, 'invoice', $customerId, new \DateTimeImmutable($taxPoint, new \DateTimeZone('Europe/Prague')), Money::ofMinor($minor, Currency::CZK), '/invoices/'.$id, [new RevenueLine($id.'-line', null, Money::ofMinor($minor, Currency::CZK))]);
    }

    private function share(string $documentId, string $month, ?string $recipient, int $minor, CostShareSource $source = CostShareSource::Document): void
    {
        $this->em->persist(new CostShare(Uuid::v7()->toRfc4122(), $documentId, 'line-1', new \DateTimeImmutable($month.'-01'), $recipient, $minor, $source));
        $this->em->flush();
        $this->em->clear();
    }

    /**
     * @param list<CostLine> $lines
     */
    private function costDocument(string $id, string $basis, array $lines): CostDocument
    {
        return new CostDocument($id, null, new \DateTimeImmutable($basis, new \DateTimeZone('Europe/Prague')), $lines, false, 'EN-'.$id, '/purchasing/'.$id);
    }

    private function calculation(): CostShareCalculation
    {
        $clock = self::getContainer()->get(InstanceClock::class);

        return new CostShareCalculation($this->em, new CostSources([$this->costSource]), new ShareSplitter(), $clock, new CostPairings(), new MappingVersions($this->em), new OccurrenceFill(new ShareSplitter()));
    }

    private function assertRefused(string $reason, callable $call): void
    {
        try {
            $call();
        } catch (MarginRefused $refused) {
            self::assertSame($reason, $refused->reason());

            return;
        }
        self::fail('Expected the call to be refused with '.$reason.'.');
    }
}
