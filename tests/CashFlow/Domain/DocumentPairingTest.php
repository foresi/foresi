<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\CashFlow\Domain;

use Foresi\CashFlow\Contract\PairingState;
use Foresi\CashFlow\Domain\DocumentPairing;
use Foresi\CashFlow\Domain\PairingCandidate;
use Foresi\CashFlow\Domain\PlannedItem;
use Foresi\Tests\CashFlow\Support\PairingFixtures;
use PHPUnit\Framework\TestCase;

/**
 * The states of a document pairing and who may move it between them.
 */
final class DocumentPairingTest extends TestCase
{
    private PlannedItem $a;
    private PlannedItem $b;

    protected function setUp(): void
    {
        $this->a = PairingFixtures::item('Item A');
        $this->b = PairingFixtures::item('Item B');
    }

    private const string USER = '01937f4e-0000-7000-8000-0000000000f1';

    public function testAutoPointsAtTheOccurrenceAndKeepsItAsTheOnlyCandidate(): void
    {
        $pairing = DocumentPairing::auto('received_document', 'doc-1', PairingFixtures::supplier(), $this->a, $this->day('2026-11-20'), $this->now());

        self::assertSame(PairingState::Auto, $pairing->state());
        self::assertSame($this->a->id(), $pairing->plannedItemId());
        self::assertSame('2026-11-20', $pairing->occurrenceOn()?->format('Y-m-d'));
        self::assertCount(1, $pairing->candidates());
        self::assertNull($pairing->decidedBy());
        self::assertFalse($pairing->isDecided());
    }

    public function testAProposalWithSeveralCandidatesPointsAtNone(): void
    {
        $pairing = DocumentPairing::propose('received_document', 'doc-1', null, [
            $this->candidate($this->a, '2026-11-20'),
            $this->candidate($this->b, '2026-11-25'),
        ], null, $this->now());

        self::assertSame(PairingState::Proposed, $pairing->state());
        self::assertNull($pairing->plannedItemId());
        self::assertNull($pairing->occurrenceOn());
        self::assertSame($this->b->id(), $pairing->candidates()[1]->plannedItemId);
        self::assertSame('2026-11-25', $pairing->candidates()[1]->occurrenceOn->format('Y-m-d'));
    }

    public function testAProposalWithOneCandidateAlsoPointsAtIt(): void
    {
        $pairing = DocumentPairing::propose('received_document', 'doc-1', PairingFixtures::supplier(), [$this->candidate($this->a, '2026-11-20')], $this->a, $this->now());

        self::assertSame($this->a->id(), $pairing->plannedItemId());
        self::assertSame('2026-11-20', $pairing->occurrenceOn()?->format('Y-m-d'));
    }

    public function testAProposalWithOneCandidateNeedsItsItem(): void
    {
        $this->expectException(\LogicException::class);

        DocumentPairing::propose('received_document', 'doc-1', null, [$this->candidate($this->a, '2026-11-20')], $this->b, $this->now());
    }

    public function testAProposalNeedsACandidate(): void
    {
        $this->expectException(\LogicException::class);

        DocumentPairing::propose('received_document', 'doc-1', null, [], null, $this->now());
    }

    public function testAutoBecomesAProposalAndBack(): void
    {
        $pairing = DocumentPairing::auto('received_document', 'doc-1', PairingFixtures::supplier(), $this->a, $this->day('2026-11-20'), $this->now());
        $later = new \DateTimeImmutable('2026-11-18 09:00:00');

        $pairing->becomeProposed([$this->candidate($this->a, '2026-11-20')], $this->a, null, $later);
        self::assertSame(PairingState::Proposed, $pairing->state());
        self::assertSame($later, $pairing->updatedAt());

        $pairing->becomeAuto($this->a, $this->day('2026-11-20'), null, $later);
        self::assertSame(PairingState::Auto, $pairing->state());
    }

    public function testConfirmingAProposalKeepsTheCandidatesAndRecordsThePerson(): void
    {
        $pairing = DocumentPairing::propose('received_document', 'doc-1', null, [
            $this->candidate($this->a, '2026-11-20'),
            $this->candidate($this->b, '2026-11-25'),
        ], null, $this->now());
        $decided = new \DateTimeImmutable('2026-11-18 09:00:00');

        $pairing->confirm($this->b, $this->day('2026-11-25'), self::USER, $decided);

        self::assertSame(PairingState::Confirmed, $pairing->state());
        self::assertSame($this->b->id(), $pairing->plannedItemId());
        self::assertCount(2, $pairing->candidates());
        self::assertSame(self::USER, $pairing->decidedBy());
        self::assertSame($decided, $pairing->decidedAt());
        self::assertTrue($pairing->isDecided());
    }

    public function testDecliningClearsTheOccurrenceButKeepsTheCandidates(): void
    {
        $pairing = DocumentPairing::auto('received_document', 'doc-1', null, $this->a, $this->day('2026-11-20'), $this->now());

        $pairing->decline(self::USER, $this->now());

        self::assertSame(PairingState::Declined, $pairing->state());
        self::assertNull($pairing->plannedItemId());
        self::assertNull($pairing->occurrenceOn());
        self::assertCount(1, $pairing->candidates());
        self::assertTrue($pairing->isDecided());
    }

    public function testCashFlowCannotChangeWhatAPersonDecided(): void
    {
        $confirmed = DocumentPairing::auto('received_document', 'doc-1', null, $this->a, $this->day('2026-11-20'), $this->now());
        $confirmed->confirm($this->a, $this->day('2026-11-20'), self::USER, $this->now());
        $declined = DocumentPairing::auto('received_document', 'doc-2', null, $this->a, $this->day('2026-11-20'), $this->now());
        $declined->decline(self::USER, $this->now());

        foreach ([$confirmed, $declined] as $pairing) {
            try {
                $pairing->becomeAuto($this->a, $this->day('2026-11-20'), null, $this->now());
                self::fail('A decided pairing was moved to auto.');
            } catch (\LogicException) {
                self::assertTrue($pairing->isDecided());
            }
            try {
                $pairing->becomeProposed([$this->candidate($this->a, '2026-11-20')], $this->a, null, $this->now());
                self::fail('A decided pairing was moved to proposed.');
            } catch (\LogicException) {
                self::assertTrue($pairing->isDecided());
            }
        }
    }

    public function testAPersonCanChangeTheirOwnDecision(): void
    {
        $pairing = DocumentPairing::auto('received_document', 'doc-1', null, $this->a, $this->day('2026-11-20'), $this->now());
        $pairing->decline(self::USER, $this->now());

        $pairing->confirm($this->a, $this->day('2026-11-20'), self::USER, $this->now());

        self::assertSame(PairingState::Confirmed, $pairing->state());
        self::assertSame($this->a->id(), $pairing->plannedItemId());
    }

    private function candidate(PlannedItem $item, string $on): PairingCandidate
    {
        return new PairingCandidate($item->id(), $this->day($on));
    }

    private function day(string $on): \DateTimeImmutable
    {
        return new \DateTimeImmutable($on);
    }

    private function now(): \DateTimeImmutable
    {
        return new \DateTimeImmutable('2026-11-17 10:00:00');
    }
}
