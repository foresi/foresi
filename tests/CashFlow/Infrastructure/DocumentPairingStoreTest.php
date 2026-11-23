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
use Foresi\Tests\CashFlow\Support\CashFlowTestCase;

/**
 * Document pairings stored and found by subject, occurrence, planned item, state and counterparty.
 */
final class DocumentPairingStoreTest extends CashFlowTestCase
{
    private const string ITEM_A = '01937f4e-0000-7000-8000-00000000000a';
    private const string ITEM_B = '01937f4e-0000-7000-8000-00000000000b';
    private const string PARTY_1 = '01937f4e-0000-7000-8000-0000000000c1';
    private const string PARTY_2 = '01937f4e-0000-7000-8000-0000000000c2';

    private CashFlowStore $store;

    protected function setUp(): void
    {
        parent::setUp();
        $this->store = self::getContainer()->get(CashFlowStore::class);
    }

    public function testFindsAPairingBySubjectAndKeepsEveryColumn(): void
    {
        $pairing = DocumentPairing::propose('received_document', 'doc-1', self::PARTY_1, [
            new PairingCandidate(self::ITEM_A, new \DateTimeImmutable('2026-11-20')),
            new PairingCandidate(self::ITEM_B, new \DateTimeImmutable('2026-11-25')),
        ], new \DateTimeImmutable('2026-11-17 10:00:00'));
        $this->store->add($pairing);
        $this->em->flush();
        $this->em->clear();

        $found = $this->store->pairing('received_document', 'doc-1');

        self::assertNotNull($found);
        self::assertSame(PairingState::Proposed, $found->state());
        self::assertSame(self::PARTY_1, $found->counterpartyId());
        self::assertNull($found->plannedItemId());
        self::assertSame(self::ITEM_B, $found->candidates()[1]->plannedItemId);
        self::assertSame('2026-11-25', $found->candidates()[1]->occurrenceOn->format('Y-m-d'));
        self::assertNull($this->store->pairing('received_document', 'doc-2'));
        self::assertNull($this->store->pairing('invoice', 'doc-1'));
    }

    public function testFindsPairingsOfAnOccurrenceAnItemAStateAndACounterparty(): void
    {
        $this->add('doc-1', self::PARTY_1, self::ITEM_A, '2026-11-20');
        $this->add('doc-2', self::PARTY_1, self::ITEM_A, '2026-11-20');
        $this->add('doc-3', self::PARTY_1, self::ITEM_A, '2026-12-20');
        $this->add('doc-4', self::PARTY_2, self::ITEM_B, '2026-11-20');
        $declined = DocumentPairing::auto('received_document', 'doc-5', self::PARTY_2, new PairingCandidate(self::ITEM_B, new \DateTimeImmutable('2026-11-20')), new \DateTimeImmutable('2026-11-17 10:00:00'));
        $declined->decline('01937f4e-0000-7000-8000-0000000000f1', new \DateTimeImmutable('2026-11-17 11:00:00'));
        $this->store->add($declined);
        $this->em->flush();
        $this->em->clear();

        self::assertSame(['doc-1', 'doc-2'], $this->subjects($this->store->pairingsOfOccurrence(self::ITEM_A, new \DateTimeImmutable('2026-11-20'))));
        self::assertSame(['doc-1', 'doc-2', 'doc-3'], $this->subjects($this->store->pairings(plannedItemId: self::ITEM_A)));
        self::assertSame(['doc-5'], $this->subjects($this->store->pairings(PairingState::Declined)));
        self::assertSame(['doc-1', 'doc-2', 'doc-3', 'doc-4'], $this->subjects($this->store->pairings(PairingState::Auto)));
        self::assertSame(['doc-4'], $this->subjects($this->store->pairings(PairingState::Auto, self::ITEM_B)));
        self::assertSame(['doc-1', 'doc-2', 'doc-3'], $this->subjects($this->store->pairingsOfCounterparty(self::PARTY_1)));
        self::assertSame([], $this->store->pairingsOfCounterparty(self::PARTY_1.'-none'));
    }

    public function testRemovesAPairing(): void
    {
        $this->add('doc-1', self::PARTY_1, self::ITEM_A, '2026-11-20');
        $this->em->flush();

        $pairing = $this->store->pairing('received_document', 'doc-1');
        self::assertNotNull($pairing);
        $this->store->remove($pairing);
        $this->em->flush();
        $this->em->clear();

        self::assertNull($this->store->pairing('received_document', 'doc-1'));
    }

    private function add(string $subjectId, string $partyId, string $itemId, string $on): void
    {
        $this->store->add(DocumentPairing::auto('received_document', $subjectId, $partyId, new PairingCandidate($itemId, new \DateTimeImmutable($on)), new \DateTimeImmutable('2026-11-17 10:00:00')));
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
