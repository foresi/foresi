<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\CashFlow\Infrastructure;

use Foresi\CashFlow\Application\CashFlowStore;
use Foresi\CashFlow\Contract\PairingState;
use Foresi\CashFlow\Domain\DocumentPairing;
use Foresi\CashFlow\Domain\PairingCandidate;
use Foresi\CashFlow\Domain\PlannedItem;
use Foresi\Party\Domain\Party;
use Foresi\Tests\CashFlow\Support\CashFlowTestCase;
use Foresi\Tests\CashFlow\Support\PairingFixtures;

/**
 * Document pairings stored and found by subject, occurrence, planned item, state and counterparty.
 */
final class DocumentPairingStoreTest extends CashFlowTestCase
{
    private CashFlowStore $store;
    private PlannedItem $a;
    private PlannedItem $b;
    private Party $one;
    private Party $two;

    protected function setUp(): void
    {
        parent::setUp();
        $this->store = self::getContainer()->get(CashFlowStore::class);
        $this->a = PairingFixtures::item('Item A');
        $this->b = PairingFixtures::item('Item B');
        $this->one = PairingFixtures::supplier('Supplier One s.r.o.', '11111111');
        $this->two = PairingFixtures::supplier('Supplier Two s.r.o.', '22222222');
        foreach ([$this->a, $this->b, $this->one, $this->two] as $record) {
            $this->em->persist($record);
        }
        $this->em->flush();
    }

    public function testFindsAPairingBySubjectAndKeepsEveryColumn(): void
    {
        $this->store->add(DocumentPairing::propose('received_document', 'doc-1', $this->one, [
            new PairingCandidate($this->a->id(), new \DateTimeImmutable('2026-11-20')),
            new PairingCandidate($this->b->id(), new \DateTimeImmutable('2026-11-25')),
        ], null, new \DateTimeImmutable('2026-11-17 10:00:00')));
        $this->em->flush();
        $this->em->clear();

        $found = $this->store->pairing('received_document', 'doc-1');

        self::assertNotNull($found);
        self::assertSame(PairingState::Proposed, $found->state());
        self::assertSame($this->one->id(), $found->counterpartyId());
        self::assertNull($found->plannedItemId());
        self::assertSame($this->b->id(), $found->candidates()[1]->plannedItemId);
        self::assertSame('2026-11-25', $found->candidates()[1]->occurrenceOn->format('Y-m-d'));
        self::assertNull($this->store->pairing('received_document', 'doc-2'));
        self::assertNull($this->store->pairing('invoice', 'doc-1'));
    }

    public function testAStoredCandidateComesBackEqualToTheOccurrenceDay(): void
    {
        $this->store->add(DocumentPairing::auto('received_document', 'doc-1', $this->one, $this->a, new \DateTimeImmutable('2026-11-20'), new \DateTimeImmutable('2026-11-17 10:00:00')));
        $this->em->flush();
        $this->em->clear();

        $found = $this->store->pairing('received_document', 'doc-1');

        self::assertNotNull($found);
        self::assertCount(1, $found->candidates());
        self::assertEquals($found->occurrenceOn(), $found->candidates()[0]->occurrenceOn);
        self::assertSame('2026-11-20 00:00:00', $found->candidates()[0]->occurrenceOn->format('Y-m-d H:i:s'));
        self::assertSame($this->a->id(), $found->candidates()[0]->plannedItemId);
    }

    public function testFindsPairingsOfAnOccurrenceAnItemAStateAndACounterparty(): void
    {
        $this->auto('doc-1', $this->one, $this->a, '2026-11-20');
        $this->auto('doc-2', $this->one, $this->a, '2026-11-20');
        $this->auto('doc-3', $this->one, $this->a, '2026-12-20');
        $this->auto('doc-4', $this->two, $this->b, '2026-11-20');
        $declined = DocumentPairing::auto('received_document', 'doc-5', $this->two, $this->b, new \DateTimeImmutable('2026-11-20'), new \DateTimeImmutable('2026-11-17 10:00:00'));
        $declined->decline('01937f4e-0000-7000-8000-0000000000f1', new \DateTimeImmutable('2026-11-17 11:00:00'));
        $this->store->add($declined);
        $this->em->flush();
        $this->em->clear();

        self::assertSame(['doc-1', 'doc-2'], $this->subjects($this->store->pairingsOfOccurrence($this->a->id(), new \DateTimeImmutable('2026-11-20'))));
        self::assertSame(['doc-1', 'doc-2', 'doc-3'], $this->subjects($this->store->pairings(plannedItemId: $this->a->id())));
        self::assertSame(['doc-5'], $this->subjects($this->store->pairings(PairingState::Declined)));
        self::assertSame(['doc-1', 'doc-2', 'doc-3', 'doc-4'], $this->subjects($this->store->pairings(PairingState::Auto)));
        self::assertSame(['doc-4'], $this->subjects($this->store->pairings(PairingState::Auto, $this->b->id())));
        self::assertSame(['doc-1', 'doc-2', 'doc-3'], $this->subjects($this->store->pairingsOfCounterparty($this->one->id())));
        self::assertSame([], $this->store->pairingsOfCounterparty($this->a->id()));
    }

    public function testRemovesAPairing(): void
    {
        $this->auto('doc-1', $this->one, $this->a, '2026-11-20');

        $pairing = $this->store->pairing('received_document', 'doc-1');
        self::assertNotNull($pairing);
        $this->store->remove($pairing);
        $this->em->flush();
        $this->em->clear();

        self::assertNull($this->store->pairing('received_document', 'doc-1'));
    }

    private function auto(string $subjectId, Party $party, PlannedItem $item, string $on): void
    {
        $this->store->add(DocumentPairing::auto('received_document', $subjectId, $party, $item, new \DateTimeImmutable($on), new \DateTimeImmutable('2026-11-17 10:00:00')));
        $this->em->flush();
    }

    /**
     * @param list<DocumentPairing> $pairings
     *
     * @return list<string>
     */
    private function subjects(array $pairings): array
    {
        $ids = array_map(static fn (DocumentPairing $p): string => $p->subjectId(), $pairings);
        sort($ids);

        return $ids;
    }
}
