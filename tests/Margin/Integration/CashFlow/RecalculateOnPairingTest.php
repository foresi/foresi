<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\Margin\Integration\CashFlow;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Foresi\CashFlow\Contract\Event\DocumentPairingChanged;
use Foresi\CashFlow\Contract\FlowDirection;
use Foresi\CashFlow\Contract\FlowSubject;
use Foresi\CashFlow\Contract\PlannedItemDirectory;
use Foresi\CashFlow\Contract\PlannedItemSummary;
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
use Foresi\Shared\Domain\Currency;
use Foresi\Shared\Domain\Money;
use Foresi\Tests\Instance\Support\InstanceZone;
use Foresi\Tests\Margin\Support\FixedCostSource;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid;

/**
 * Margin over Cash flow's contracts: the pairings of received documents,
 * the planned items, and the shares counted again when a pairing changes,
 * also while Cash flow has not stored the change yet.
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
        };
        $this->pairings = new CashFlowPairings($this->stored, $items);
        $calculation = new CostShareCalculation($this->em, new CostSources([$this->source]), new ShareSplitter(), self::getContainer()->get(InstanceClock::class), new CostPairings([$this->pairings]), new MappingVersions($this->em), new OccurrenceFill(new ShareSplitter()));
        $this->listener = new RecalculateOnPairing($this->pairings, $calculation);

        $this->em->persist(new PlannedAllocation(Uuid::v7()->toRfc4122(), self::ITEM, new \DateTimeImmutable('2026-05-01'), new Allocation(AllocationKind::Fixed, [
            new AllocationShare(Recipient::client(self::CLIENT_A), amountCzk: Money::ofMinor(60_000, Currency::CZK)),
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

    public function testAPairingFillsTheOccurrenceBeforeCashFlowHasStoredIt(): void
    {
        $this->stored->pair(self::LATER, '2026-05-01');
        $this->changed(self::LATER, 'auto', '2026-05-01');

        // Cash flow has not flushed the pairing of the earlier document yet.
        $this->changed(self::EARLIER, 'auto', '2026-05-01');

        self::assertSame([
            self::EARLIER.' '.self::CLIENT_A.' 60000 planned_item',
            self::EARLIER.' own 7200 planned_item',
            self::LATER.' own 15750 planned_item',
        ], $this->shares());
    }

    public function testUnpairingRecalculatesTheWholeOccurrence(): void
    {
        $this->stored->pair(self::EARLIER, '2026-05-01');
        $this->stored->pair(self::LATER, '2026-05-01');
        $this->changed(self::EARLIER, 'auto', '2026-05-01');

        // The pairing is gone, though Cash flow still has its row until it flushes.
        $this->changed(self::EARLIER, null, null);

        self::assertSame([
            self::EARLIER.' - 67200 unassigned',
            self::LATER.' '.self::CLIENT_A.' 15750 planned_item',
        ], $this->shares());
    }

    public function testAProposedPairingCarriesNoMapping(): void
    {
        $this->changed(self::EARLIER, 'proposed', '2026-05-01');

        self::assertSame([self::EARLIER.' - 67200 unassigned'], $this->shares());
    }

    public function testThePairingOfAnotherSubjectIsIgnored(): void
    {
        $this->listener->onPairingChanged(new DocumentPairingChanged('invoice', self::EARLIER, 'auto', self::ITEM, '2026-05-01', null));
        $this->em->flush();

        self::assertSame([], $this->shares());
    }

    private function changed(string $documentId, ?string $state, ?string $on): void
    {
        $this->listener->onPairingChanged(new DocumentPairingChanged(CashFlowPairings::RECEIVED_DOCUMENT, $documentId, $state, null === $state ? null : self::ITEM, $on, null));
        $this->em->flush();
    }

    private function document(string $id, string $basis, int $minor): void
    {
        $this->source->documents[$id] = new CostDocument($id, null, new \DateTimeImmutable($basis, new \DateTimeZone('Europe/Prague')), [new CostLine('line-1', 'Hosting', Money::ofMinor($minor, Currency::CZK))], false, 'EN '.$id, '/purchasing/'.$id);
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
