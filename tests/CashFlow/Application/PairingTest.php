<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\CashFlow\Application;

use Foresi\CashFlow\Application\CashFlowStore;
use Foresi\CashFlow\Application\CreatePlannedItemInput;
use Foresi\CashFlow\Application\Pairing;
use Foresi\CashFlow\Application\PlannedItemVatRule;
use Foresi\CashFlow\Application\PlannedOccurrences;
use Foresi\CashFlow\Contract\FlowDirection;
use Foresi\CashFlow\Contract\FlowSubject;
use Foresi\CashFlow\Contract\PairableDocument;
use Foresi\CashFlow\Contract\PairingState;
use Foresi\CashFlow\Domain\DocumentPairing;
use Foresi\CashFlow\Domain\PairingCandidate;
use Foresi\CashFlow\Domain\PlannedItem;
use Foresi\Instance\Application\InstanceClock;
use Foresi\Party\Application\TransactionalWork;
use Foresi\Party\Contract\VatPeriodLength;
use Foresi\Party\Domain\Party;
use Foresi\Party\Domain\PartyKind;
use Foresi\Party\Domain\RegistrationNumber;
use Foresi\Party\Domain\VatStatus;
use Foresi\Party\Domain\VatStatusPeriod;
use Foresi\Shared\Application\Event\EventRecorder;
use Foresi\Shared\Domain\Currency;
use Foresi\Shared\Domain\Money;
use Foresi\Tests\CashFlow\Support\CashFlowTestCase;
use Foresi\Tests\CashFlow\Support\FakePairableDocuments;
use Psr\Clock\ClockInterface;

/**
 * The rules that pair a received document with a planned occurrence on their
 * own, propose it, or leave it alone. Today is 17 November 2026 in Prague.
 */
final class PairingTest extends CashFlowTestCase
{
    private const string EVENT = 'cash-flow.document_pairing.changed';

    private FakePairableDocuments $documents;
    private Pairing $pairing;
    private Party $supplier;

    protected function setUp(): void
    {
        parent::setUp();
        $this->supplier = $this->supplier('Cloud Host s.r.o.', '11111111');
        $this->documents = new FakePairableDocuments();
        $container = self::getContainer();
        $this->pairing = new Pairing(
            $container->get(CashFlowStore::class),
            $container->get(PlannedItemVatRule::class),
            $container->get(PlannedOccurrences::class),
            $container->get(InstanceClock::class),
            $container->get(TransactionalWork::class),
            $container->get(EventRecorder::class),
            $container->get(ClockInterface::class),
            [$this->documents],
        );
    }

    public function testOneItemOneDocumentWithinTolerancePairsAutomatically(): void
    {
        $item = $this->monthly('Cloud Host', '32.00', '2026-05-20', tolerance: '1.00');
        $document = $this->document('doc-1', '32.50', '2026-05-18');

        $this->pairing->consider($document);

        $row = $this->row('doc-1');
        self::assertSame(PairingState::Auto, $row->state());
        self::assertSame($item->id(), $row->plannedItemId());
        self::assertSame('2026-05-20', $row->occurrenceOn()?->format('Y-m-d'));
        self::assertSame($this->supplier->id(), $row->counterpartyId());
        self::assertCount(1, $row->candidates());
        self::assertNull($row->decidedBy());
    }

    public function testADriftingAmountOutsideTheToleranceIsProposed(): void
    {
        $item = $this->monthly('Cloud Host', '32.00', '2026-05-20', tolerance: '1.00');

        $this->pairing->consider($this->document('doc-1', '33.02', '2026-05-18'));
        $this->pairing->consider($this->document('doc-2', '32.50', '2026-06-18'));

        self::assertSame(PairingState::Proposed, $this->row('doc-1')->state());
        self::assertSame($item->id(), $this->row('doc-1')->plannedItemId(), 'One candidate fills the occurrence.');
        self::assertSame(PairingState::Auto, $this->row('doc-2')->state());
    }

    public function testASecondDocumentInThePeriodTurnsBothIntoProposals(): void
    {
        $this->monthly('Cloud Host', '32.00', '2026-05-20', tolerance: '1.00');
        $this->pairing->consider($this->document('doc-1', '32.00', '2026-05-10'));
        $alone = $this->row('doc-1')->state();

        $this->pairing->consider($this->document('doc-2', '7.50', '2026-05-12'));

        self::assertSame(PairingState::Auto, $alone);
        self::assertSame(PairingState::Proposed, $this->row('doc-1')->state());
        self::assertSame(PairingState::Proposed, $this->row('doc-2')->state());
    }

    public function testTwoItemsOfOneSupplierAreProposedWithBothCandidates(): void
    {
        $a = $this->monthly('Cloud Host', '32.00', '2026-05-20');
        $b = $this->monthly('Voice API', '32.00', '2026-05-25');

        $this->pairing->consider($this->document('doc-1', '32.00', '2026-05-18'));

        $row = $this->row('doc-1');
        self::assertSame(PairingState::Proposed, $row->state());
        self::assertNull($row->plannedItemId());
        self::assertEqualsCanonicalizing([$a->id(), $b->id()], array_map(static fn (PairingCandidate $candidate): string => $candidate->plannedItemId, $row->candidates()));
    }

    public function testADeclinedDocumentIsNotPairedAgain(): void
    {
        $this->monthly('Cloud Host', '32.00', '2026-05-20');
        $this->pairing->consider($this->document('doc-1', '33.00', '2026-05-18'));
        $this->row('doc-1')->decline('01937f4e-0000-7000-8000-0000000000f1', new \DateTimeImmutable('2026-11-17 11:00:00'));
        $this->em->flush();

        $this->pairing->consider($this->document('doc-1', '32.00', '2026-05-18'));

        self::assertSame(PairingState::Declined, $this->row('doc-1')->state());
        self::assertNull($this->row('doc-1')->plannedItemId());
    }

    public function testADeclinedSiblingDoesNotStopADocumentFromPairingAutomatically(): void
    {
        $item = $this->monthly('Cloud Host', '32.00', '2026-05-20');
        $this->pairing->consider($this->document('doc-1', '33.00', '2026-05-10'));
        $this->row('doc-1')->decline('01937f4e-0000-7000-8000-0000000000f1', new \DateTimeImmutable('2026-11-17 11:00:00'));
        $this->em->flush();

        $this->pairing->consider($this->document('doc-2', '32.00', '2026-05-12'));

        self::assertSame(PairingState::Auto, $this->row('doc-2')->state());
        self::assertSame($item->id(), $this->row('doc-2')->plannedItemId());
    }

    public function testAConfirmedPairingIsNeverChangedByConsider(): void
    {
        $a = $this->monthly('Cloud Host', '32.00', '2026-05-20');
        $b = $this->monthly('Voice API', '50.00', '2026-06-20');
        $this->pairing->consider($this->document('doc-1', '33.00', '2026-05-18'));
        $row = $this->row('doc-1');
        $b = $this->store()->plannedItem($b->id()) ?? self::fail('No item');
        $row->confirm($b, new \DateTimeImmutable('2026-06-20'), '01937f4e-0000-7000-8000-0000000000f1', new \DateTimeImmutable('2026-11-17 11:00:00'));
        $this->em->flush();
        $before = \count($this->events(self::EVENT));

        $this->pairing->consider($this->document('doc-1', '32.00', '2026-05-18'));

        $row = $this->row('doc-1');
        self::assertSame(PairingState::Confirmed, $row->state());
        self::assertSame($b->id(), $row->plannedItemId());
        self::assertCount($before, $this->events(self::EVENT));
        self::assertNotSame($a->id(), $row->plannedItemId());
    }

    public function testADocumentInAnotherCurrencyIsProposed(): void
    {
        $item = $this->monthly('Cloud Host', '32.00', '2026-05-20');

        $this->pairing->consider($this->document('doc-1', '32.00', '2026-05-18', Currency::EUR));

        self::assertSame(PairingState::Proposed, $this->row('doc-1')->state());
        self::assertSame($item->id(), $this->row('doc-1')->plannedItemId());
    }

    public function testThePeriodIsDecidedInPragueTime(): void
    {
        $this->clock->modify('2026-11-30 23:30:00');
        $item = $this->monthly('Cloud Host', '32.00', '2026-11-30');

        $this->pairing->consider($this->document('doc-1', '32.00', '2026-11-30'));
        $this->pairing->consider($this->document('doc-2', '32.00', '2026-12-01'));

        self::assertSame($item->id(), $this->row('doc-1')->plannedItemId());
        self::assertSame('2026-11-30', $this->row('doc-1')->occurrenceOn()?->format('Y-m-d'), 'The last day of November is in November.');
        self::assertSame('2026-12-30', $this->row('doc-2')->occurrenceOn()?->format('Y-m-d'), 'The first of December is in December.');
        self::assertSame(PairingState::Auto, $this->row('doc-2')->state(), 'Neither document shares a month with the other.');
    }

    public function testForgettingADocumentLetsTheRemainingOnePairAutomatically(): void
    {
        $item = $this->monthly('Cloud Host', '32.00', '2026-05-20');
        $this->pairing->consider($this->document('doc-1', '32.00', '2026-05-10'));
        $this->pairing->consider($this->document('doc-2', '7.50', '2026-05-12'));
        self::assertSame(PairingState::Proposed, $this->row('doc-1')->state());

        $this->documents->remove('received_document', 'doc-2');
        $this->pairing->forget(new FlowSubject('received_document', 'doc-2'));

        self::assertNull($this->store()->pairing('received_document', 'doc-2'));
        self::assertSame(PairingState::Auto, $this->row('doc-1')->state());
        self::assertSame($item->id(), $this->row('doc-1')->plannedItemId());
    }

    public function testAnItemWithoutAToleranceAcceptsOnlyTheExactAmount(): void
    {
        $this->monthly('Cloud Host', '32.00', '2026-05-20');

        $this->pairing->consider($this->document('doc-1', '32.01', '2026-05-18'));
        $this->pairing->consider($this->document('doc-2', '32.00', '2026-06-18'));

        self::assertSame(PairingState::Proposed, $this->row('doc-1')->state());
    }

    public function testASkippedOccurrenceIsNotACandidate(): void
    {
        $item = $this->monthly('Cloud Host', '32.00', '2026-05-20');
        $this->writes()->skip(new \Foresi\CashFlow\Application\SkipPlannedOccurrenceInput($item->id(), '2026-05-20'));

        $this->pairing->consider($this->document('doc-1', '32.00', '2026-05-18'));

        self::assertNull($this->store()->pairing('received_document', 'doc-1'));
    }

    public function testAnOccurrenceAfterTheEndIsNotACandidateAndAnOldRowIsRemoved(): void
    {
        $this->monthly('Cloud Host', '32.00', '2026-05-20', endsOn: '2026-06-20');
        $this->pairing->consider($this->document('doc-1', '32.00', '2026-06-18'));
        self::assertSame(PairingState::Auto, $this->row('doc-1')->state());

        $this->pairing->consider($this->document('doc-2', '32.00', '2026-07-18'));
        self::assertNull($this->store()->pairing('received_document', 'doc-2'));

        // The same document is now dated outside the item: its row goes.
        $this->pairing->consider($this->document('doc-1', '32.00', '2026-07-18'));
        self::assertNull($this->store()->pairing('received_document', 'doc-1'));
    }

    public function testTheExpectedAmountCarriesTheVatOnTopForAVatPayer(): void
    {
        $this->em->persist(new VatStatusPeriod(VatStatus::Registered, VatPeriodLength::Month, new \DateTimeImmutable('2026-01-01')));
        $this->em->flush();
        $this->monthly('Cloud Host', '100.00', '2026-05-20', vat: ['excluded', '21']);

        $this->pairing->consider($this->document('doc-1', '121.00', '2026-05-18'));
        $this->pairing->consider($this->document('doc-2', '100.00', '2026-06-18'));

        self::assertSame(PairingState::Auto, $this->row('doc-1')->state());
        self::assertSame(PairingState::Proposed, $this->row('doc-2')->state());
    }

    public function testTheEventCarriesTheFactsAndIsNotRecordedWhenNothingChanged(): void
    {
        $item = $this->monthly('Cloud Host', '32.00', '2026-05-20');
        $document = $this->document('doc-1', '32.00', '2026-05-18');

        $this->pairing->consider($document);
        $this->pairing->consider($document);

        $first = [[
            'subject_type' => 'received_document',
            'subject_id' => 'doc-1',
            'state' => 'auto',
            'planned_item_id' => $item->id(),
            'occurrence_on' => '2026-05-20',
            'previous_planned_item_id' => null,
        ]];
        $afterConsider = $this->events(self::EVENT);
        self::assertSame($first, $afterConsider);

        $this->pairing->forget(new FlowSubject('received_document', 'doc-1'));

        $events = $this->events(self::EVENT);
        self::assertCount(2, $events);
        self::assertSame([null, null, $item->id()], [$events[1]['state'], $events[1]['planned_item_id'], $events[1]['previous_planned_item_id']]);

        $this->pairing->forget(new FlowSubject('received_document', 'doc-1'));
        $afterSecondForget = $this->events(self::EVENT);
        self::assertCount(2, $afterSecondForget);
    }

    /**
     * @param array{string, string}|null $vat
     */
    private function monthly(string $name, string $amount, string $on, ?string $tolerance = null, string $currency = 'USD', ?string $endsOn = null, ?array $vat = null): PlannedItem
    {
        return $this->writes()->create(new CreatePlannedItemInput(
            'out', $name, 'software', $amount, $on, $currency, 'month', 1, false, $endsOn, null, $this->supplier->id(), true,
            null, null, $tolerance, $vat[0] ?? 'none', $vat[1] ?? null,
        ));
    }

    private function document(string $id, string $amount, string $on, Currency $currency = Currency::USD): PairableDocument
    {
        return $this->documents->add(new PairableDocument(
            new FlowSubject('received_document', $id),
            $this->supplier->id(),
            FlowDirection::Out,
            Money::of($amount, $currency),
            new \DateTimeImmutable($on),
        ));
    }

    private function row(string $id): DocumentPairing
    {
        $this->em->clear();

        return $this->store()->pairing('received_document', $id) ?? self::fail('No pairing of '.$id);
    }

    private function store(): CashFlowStore
    {
        return self::getContainer()->get(CashFlowStore::class);
    }

    private function supplier(string $name, string $number): Party
    {
        $party = new Party(PartyKind::Company, $name, new RegistrationNumber('CZ', $number), null, '');
        $this->em->persist($party);
        $this->em->flush();

        return $party;
    }
}
