<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\Margin\Integration\CashFlow;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Doctrine\Persistence\ManagerRegistry;
use Foresi\CashFlow\Contract\Event\DocumentPairingChanged;
use Foresi\CashFlow\Contract\FlowDirection;
use Foresi\CashFlow\Contract\FlowSubject;
use Foresi\CashFlow\Contract\PlannedItemDirectory;
use Foresi\CashFlow\Contract\PlannedItemSummary;
use Foresi\Events\Application\DeliverEvent;
use Foresi\Events\Infrastructure\Catalogue\EventCatalogue;
use Foresi\Events\Infrastructure\Dbal\EventLogTable;
use Foresi\Events\Infrastructure\Payload\EventPayloads;
use Foresi\Events\Infrastructure\Relay\DeliverEventHandler;
use Foresi\Events\Infrastructure\Relay\DeliveryWork;
use Foresi\Events\Infrastructure\Relay\EventConsumptions;
use Foresi\Events\Infrastructure\Relay\EventListeners;
use Foresi\Instance\Application\InstanceClock;
use Foresi\Margin\Application\CostPairings;
use Foresi\Margin\Application\CostShareCalculation;
use Foresi\Margin\Application\CostSources;
use Foresi\Margin\Application\MappingVersions;
use Foresi\Margin\Application\OccurrenceFill;
use Foresi\Margin\Contract\CostDocument;
use Foresi\Margin\Contract\CostLine;
use Foresi\Margin\Domain\Allocation;
use Foresi\Margin\Domain\AllocationKind;
use Foresi\Margin\Domain\AllocationShare;
use Foresi\Margin\Domain\CostShare;
use Foresi\Margin\Domain\PlannedAllocation;
use Foresi\Margin\Domain\Recipient;
use Foresi\Margin\Domain\ShareSplitter;
use Foresi\Margin\Integration\CashFlow\CashFlowPairings;
use Foresi\Margin\Integration\CashFlow\RecalculateOnPairing;
use Foresi\ReceivedDocuments\Integration\CashFlow\ReceivedDocumentPairables;
use Foresi\Shared\Application\Event\EventOutbox;
use Foresi\Shared\Domain\Currency;
use Foresi\Shared\Domain\Id;
use Foresi\Shared\Domain\Money;
use Foresi\Shared\Infrastructure\Doctrine\DoctrineUnitOfWork;
use Foresi\Tests\Instance\Support\InstanceZone;
use Foresi\Tests\Margin\Support\FixedCostSource;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\DependencyInjection\ServiceLocator;
use Symfony\Component\Uid\Uuid;

/**
 * Margin over Cash flow's contracts: the pairings of received documents,
 * the planned items, and the shares counted again when a pairing changes,
 * delivered as the relay delivers an event: after the pairing is stored, in
 * a unit of work of its own, once per event.
 */
final class RecalculateOnPairingTest extends KernelTestCase
{
    private const string ITEM = '0197a1de-0000-7000-8000-0000000000e1';
    private const string EARLIER = '0197a1de-0000-7000-8000-0000000000d1';
    private const string LATER = '0197a1de-0000-7000-8000-0000000000d2';
    private const string CLIENT_A = '0197a1de-0000-7000-8000-00000000000a';

    private EntityManagerInterface $em;
    private FixedCostSource $source;
    private StoredPairings $stored;
    private CashFlowPairings $pairings;
    private RecalculateOnPairing $listener;

    protected function setUp(): void
    {
        self::bootKernel();
        InstanceZone::set(self::getContainer());
        $this->em = self::getContainer()->get('doctrine.orm.entity_manager');
        $tool = new SchemaTool($this->em);
        $metadata = $this->em->getMetadataFactory()->getAllMetadata();
        $tool->dropSchema($metadata);
        $tool->createSchema($metadata);

        $this->source = new FixedCostSource();
        $this->stored = new StoredPairings();
        $items = new class implements PlannedItemDirectory {
            public function one(string $id): ?PlannedItemSummary
            {
                return '0197a1de-0000-7000-8000-0000000000e1' === $id ? new PlannedItemSummary($id, 'Cloud Host', FlowDirection::Out, '0197a1de-0000-7000-8000-0000000000c1', true, '/cash-flow/'.$id) : null;
            }

            public function occurrenceHolding(string $id, \DateTimeImmutable $day): ?\DateTimeImmutable
            {
                return null;
            }

            public function costs(): array
            {
                return [new PlannedItemSummary('0197a1de-0000-7000-8000-0000000000e1', 'Cloud Host', FlowDirection::Out, '0197a1de-0000-7000-8000-0000000000c1', true, '/cash-flow/0197a1de-0000-7000-8000-0000000000e1')];
            }
        };
        $this->pairings = new CashFlowPairings($this->stored, $items);
        $calculation = new CostShareCalculation($this->em, new CostSources([$this->source]), new ShareSplitter(), self::getContainer()->get(InstanceClock::class), new CostPairings([$this->pairings]), new MappingVersions($this->em), new OccurrenceFill(new ShareSplitter()));
        $this->listener = new RecalculateOnPairing($calculation);

        $this->em->persist(new PlannedAllocation(Uuid::v7()->toRfc4122(), self::ITEM, new \DateTimeImmutable('2026-05-01'), new Allocation(AllocationKind::Fixed, [
            new AllocationShare(Recipient::client(self::CLIENT_A), amountCzk: Money::ofMinor(60_000, Currency::of('CZK'))),
            new AllocationShare(Recipient::own(), rest: true),
        ]), new \DateTimeImmutable(), 'user'));
        $this->em->flush();
        $this->document(self::EARLIER, '2026-05-03', 67_200);
        $this->document(self::LATER, '2026-05-20', 15_750);
    }

    public function testTheItemOfAPairingIsReadThroughCashFlow(): void
    {
        $item = $this->pairings->item(self::ITEM);

        self::assertNotNull($item);
        self::assertSame(['Cloud Host', true, true, '0197a1de-0000-7000-8000-0000000000c1'], [$item->name, $item->expense, $item->recurring, $item->counterpartyId]);
        self::assertNull($this->pairings->item('0197a1de-0000-7000-8000-0000000000e9'));
        self::assertNull($this->pairings->item('not-an-id'));
    }

    public function testOnlyReceivedDocumentsArePairedForTheMargin(): void
    {
        $this->stored->pair(self::EARLIER, '2026-05-01');
        $this->stored->pairs[] = [new FlowSubject('invoice', self::LATER), '2026-05-01'];

        self::assertSame([self::EARLIER], $this->pairings->documentsOfOccurrence(self::ITEM, new \DateTimeImmutable('2026-05-01')));
        self::assertSame([self::EARLIER], $this->pairings->documentsOfItemFrom(self::ITEM, new \DateTimeImmutable('2026-05-01')));
        self::assertSame([], $this->pairings->documentsOfItemFrom(self::ITEM, new \DateTimeImmutable('2026-06-01')));
        self::assertSame('2026-05-01', $this->pairings->pairingOf(self::EARLIER)?->occurrenceOn->format('Y-m-d'));
        self::assertNull($this->pairings->pairingOf(self::LATER));
    }

    public function testTheOccurrencesWithAPairedCostAreListedOnceEach(): void
    {
        $this->stored->pair(self::EARLIER, '2026-05-01');
        $this->stored->pair(self::LATER, '2026-05-01');
        $this->stored->pairs[] = [new FlowSubject('invoice', self::LATER), '2026-04-01'];
        $this->stored->pair('0197a1de-0000-7000-8000-0000000000d3', '2026-06-01');

        self::assertSame(['2026-05-01', '2026-06-01'], array_map(static fn (\DateTimeImmutable $day): string => $day->format('Y-m-d'), $this->pairings->occurrencesWithDocumentsFrom(self::ITEM, new \DateTimeImmutable('2026-04-01'))));
        self::assertSame(['2026-06-01'], array_map(static fn (\DateTimeImmutable $day): string => $day->format('Y-m-d'), $this->pairings->occurrencesWithDocumentsFrom(self::ITEM, new \DateTimeImmutable('2026-06-01'))));
        self::assertSame([], $this->pairings->occurrencesWithDocumentsFrom('not-an-id', new \DateTimeImmutable('2026-04-01')));
        self::assertSame(2, $this->stored->itemReads, 'An id that is no id is not looked up.');
        self::assertNull($this->pairings->occurrenceHolding(self::ITEM, new \DateTimeImmutable('2026-05-10')));
    }

    public function testTheOccurrencesWithAPairedCostAreListedOldestFirst(): void
    {
        $this->stored->pair(self::LATER, '2026-06-01');
        $this->stored->pair(self::EARLIER, '2026-05-01');

        $days = $this->pairings->occurrencesWithDocumentsFrom(self::ITEM, new \DateTimeImmutable('2026-04-01'));

        self::assertSame(['2026-05-01', '2026-06-01'], array_map(static fn (\DateTimeImmutable $day): string => $day->format('Y-m-d'), $days));
        self::assertSame([0, 1], array_keys($days), 'a list');
    }

    public function testThePlannedCostsAreReadThroughCashFlowAsExpenses(): void
    {
        $items = $this->pairings->costItems();

        self::assertCount(1, $items);
        self::assertSame([self::ITEM, 'Cloud Host', true, true, '0197a1de-0000-7000-8000-0000000000c1', '/cash-flow/'.self::ITEM], [$items[0]->id, $items[0]->name, $items[0]->expense, $items[0]->recurring, $items[0]->counterpartyId, $items[0]->url]);
    }

    public function testEveryReceivedDocumentOfTheItemIsListed(): void
    {
        $this->stored->pair(self::EARLIER, '2026-05-01');
        $this->stored->pair(self::LATER, '2026-06-01');

        self::assertSame([self::EARLIER, self::LATER], $this->pairings->documentsOfItemFrom(self::ITEM, new \DateTimeImmutable('2026-05-01')));
    }

    public function testAPairingRecalculatesTheWholeOccurrence(): void
    {
        $this->stored->pair(self::LATER, '2026-05-01');
        $this->changed(self::LATER, 'auto', '2026-05-01');
        self::assertSame([self::LATER.' '.self::CLIENT_A.' 15750 planned_item'], $this->shares());

        $this->stored->pair(self::EARLIER, '2026-05-01');
        $this->changed(self::EARLIER, 'auto', '2026-05-01');

        self::assertSame([
            self::EARLIER.' '.self::CLIENT_A.' 60000 planned_item',
            self::EARLIER.' own 7200 planned_item',
            self::LATER.' own 15750 planned_item',
        ], $this->shares(), 'The earlier document now fills the fixed amount, and the later one was counted again.');
    }

    public function testUnpairingRecalculatesTheWholeOccurrence(): void
    {
        $this->stored->pair(self::EARLIER, '2026-05-01');
        $this->stored->pair(self::LATER, '2026-05-01');
        $this->changed(self::EARLIER, 'auto', '2026-05-01');

        $this->stored->unpair(self::EARLIER);
        $this->changed(self::EARLIER, null, null);

        self::assertSame([
            self::EARLIER.' - 67200 unassigned',
            self::LATER.' '.self::CLIENT_A.' 15750 planned_item',
        ], $this->shares(), 'The occurrence the document left was found from its shares.');
    }

    public function testAnEventDeliveredTwiceOrLateLeavesTheStoredState(): void
    {
        $this->stored->pair(self::EARLIER, '2026-05-01');
        $first = $this->changed(self::EARLIER, 'auto', '2026-05-01');
        $this->stored->unpair(self::EARLIER);
        $this->changed(self::EARLIER, null, null);

        // The pairing event comes again after the unpairing: the shares follow the stored state.
        $this->handler()(new DeliverEvent($first, RecalculateOnPairing::class));
        $this->listener->__invoke(new \Foresi\Shared\Contract\Event\RecordedEvent(Id::generate(), RecalculateOnPairing::EVENT, 1, new \DateTimeImmutable(), null, $this->event(self::EARLIER, 'auto', '2026-05-01')));

        self::assertSame([self::EARLIER.' - 67200 unassigned'], $this->shares());
    }

    public function testThePairingOfAnotherSubjectIsIgnored(): void
    {
        $this->stored->pair(self::EARLIER, '2026-05-01');
        $this->deliver(new DocumentPairingChanged('invoice', self::EARLIER, 'auto', self::ITEM, '2026-05-01', null));

        self::assertSame([], $this->shares());
    }

    public function testTheListenerIsRegisteredForCashFlowsEvent(): void
    {
        self::assertContains(RecalculateOnPairing::class, self::getContainer()->get(EventListeners::class)->idsFor(RecalculateOnPairing::EVENT));
    }

    public function testTheSubjectTypeIsPurchasingsReceivedDocument(): void
    {
        // Read at run time: Margin keeps its own copy, as it may not use Purchasing's integration.
        self::assertSame(CashFlowPairings::RECEIVED_DOCUMENT, new \ReflectionClassConstant(ReceivedDocumentPairables::class, 'TYPE')->getValue());
    }

    /**
     * Records the event as Cash flow did and delivers it to the listener.
     *
     * @return string the id of the event
     */
    private function changed(string $documentId, ?string $state, ?string $on): string
    {
        return $this->deliver($this->event($documentId, $state, $on));
    }

    private function event(string $documentId, ?string $state, ?string $on): DocumentPairingChanged
    {
        return new DocumentPairingChanged(CashFlowPairings::RECEIVED_DOCUMENT, $documentId, $state, null === $state ? null : self::ITEM, $on, null);
    }

    private function deliver(DocumentPairingChanged $event): string
    {
        $id = Id::generate();
        $this->em->getConnection()->insert(EventLogTable::LOG, [
            EventLogTable::ID => $id,
            EventLogTable::NAME => RecalculateOnPairing::EVENT,
            EventLogTable::VERSION => 1,
            EventLogTable::OCCURRED_AT => new \DateTimeImmutable('2026-05-21 10:00:00', new \DateTimeZone('UTC')),
            EventLogTable::SUBJECT_TYPE => $event->subjectType,
            EventLogTable::SUBJECT_ID => $event->subjectId,
            EventLogTable::DATA => new EventPayloads()->normalise($event),
            EventLogTable::CORRELATION_ID => null,
            EventLogTable::ACTOR_KIND => 'system',
            EventLogTable::POSITION => null,
        ], [EventLogTable::OCCURRED_AT => Types::DATETIME_IMMUTABLE, EventLogTable::DATA => Types::JSON]);
        $this->em->clear();

        $this->handler()(new DeliverEvent($id, RecalculateOnPairing::class));

        return $id;
    }

    private function handler(): DeliverEventHandler
    {
        $container = self::getContainer();
        $listener = $this->listener;
        $outbox = new class implements EventOutbox {
            public function committed(): void
            {
            }

            public function rolledBack(): void
            {
            }
        };

        return new DeliverEventHandler(
            $this->em->getConnection(),
            new EventListeners(new ServiceLocator([RecalculateOnPairing::class => static fn (): RecalculateOnPairing => $listener])),
            $container->get(EventCatalogue::class),
            new EventPayloads(),
            new DeliveryWork(new DoctrineUnitOfWork($this->em, $container->get(ManagerRegistry::class), $outbox)),
            new EventConsumptions($this->em->getConnection(), new MockClock('2026-05-21 11:00:00', 'UTC')),
            new NullLogger(),
        );
    }

    private function document(string $id, string $basis, int $minor): void
    {
        $this->source->documents[$id] = new CostDocument($id, null, new \DateTimeImmutable($basis, new \DateTimeZone('Europe/Prague')), [new CostLine('line-1', 'Hosting', Money::ofMinor($minor, Currency::of('CZK')))], false, 'EN '.$id, '/received-documents/'.$id);
    }

    /**
     * @return list<string> "document recipient amount source", sorted
     */
    private function shares(): array
    {
        $this->em->clear();
        $rows = array_map(static fn (CostShare $share): string => \sprintf('%s %s %d %s', $share->documentId(), $share->recipient()?->toString() ?? '-', $share->amount()->minor(), $share->source()->value), $this->em->getRepository(CostShare::class)->findAll());
        sort($rows);

        return $rows;
    }
}
