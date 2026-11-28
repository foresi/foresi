<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\CashFlow\Application;

use Foresi\CashFlow\Application\CashFlowAccess;
use Foresi\CashFlow\Application\CashFlowRefused;
use Foresi\CashFlow\Application\CashFlowStore;
use Foresi\CashFlow\Application\ChangePlannedItemInput;
use Foresi\CashFlow\Application\CreatePlannedItemInput;
use Foresi\CashFlow\Application\DeletePlannedItemInput;
use Foresi\CashFlow\Application\EndPlannedItemInput;
use Foresi\CashFlow\Application\Pairing;
use Foresi\CashFlow\Application\PlannedItemRules;
use Foresi\CashFlow\Application\PlannedItemVatRule;
use Foresi\CashFlow\Application\PlannedItemWrites;
use Foresi\CashFlow\Application\PlannedOccurrences;
use Foresi\CashFlow\Application\SkipPlannedOccurrenceInput;
use Foresi\CashFlow\Contract\FlowDirection;
use Foresi\CashFlow\Contract\FlowSubject;
use Foresi\CashFlow\Contract\PairableDocument;
use Foresi\CashFlow\Contract\PairingState;
use Foresi\CashFlow\Domain\DocumentPairing;
use Foresi\CashFlow\Domain\PairingCandidate;
use Foresi\CashFlow\Domain\PlannedItem;
use Foresi\Instance\Application\InstanceClock;
use Foresi\Party\Domain\Party;
use Foresi\Party\Domain\PartyKind;
use Foresi\Party\Domain\RegistrationNumber;
use Foresi\Shared\Application\TransactionalWork;
use Foresi\Shared\Contract\Event\EventRecorder;
use Foresi\Shared\Domain\Currency;
use Foresi\Shared\Domain\Money;
use Foresi\Tests\CashFlow\Support\CashFlowTestCase;
use Foresi\Tests\CashFlow\Support\FakePairableDocuments;
use Psr\Clock\ClockInterface;

/**
 * What the writes of a planned item do to the documents paired with it: a
 * delete is refused while a pairing stands and lets the proposals go, a change
 * that moves what belongs to the item, or a skipped occurrence, makes Cash flow
 * look at the documents again. Today is 17 November 2026 in Prague.
 */
final class PlannedItemPairingsTest extends CashFlowTestCase
{
    private FakePairableDocuments $documents;
    private PlannedItemWrites $writes;
    private Party $supplier;
    private Party $other;

    protected function setUp(): void
    {
        parent::setUp();
        $this->supplier = $this->party('Cloud Host s.r.o.', '11111111');
        $this->other = $this->party('Voice API s.r.o.', '22222222');
        $this->documents = new FakePairableDocuments();
        $c = self::getContainer();
        $pairing = new Pairing(
            $c->get(CashFlowStore::class), $c->get(PlannedItemVatRule::class), $c->get(PlannedOccurrences::class), $c->get(InstanceClock::class),
            $c->get(TransactionalWork::class), $c->get(EventRecorder::class), $c->get(ClockInterface::class), [$this->documents],
        );
        $this->writes = new PlannedItemWrites(
            $c->get(CashFlowAccess::class), $c->get(PlannedItemRules::class), $c->get(CashFlowStore::class), $c->get(TransactionalWork::class),
            $c->get(EventRecorder::class), $c->get(InstanceClock::class), $c->get(ClockInterface::class), $pairing,
        );
    }

    public function testDeletingAnItemWithPairingsIsRefused(): void
    {
        $item = $this->monthly('Cloud Host', '32.00');
        $this->document('doc-1', '32.00', '2026-05-18');
        $this->considerAll();
        self::assertSame(PairingState::Auto, $this->row('doc-1')->state());

        try {
            $this->writes->delete(new DeletePlannedItemInput($item->id()));
            self::fail('The delete should be refused.');
        } catch (CashFlowRefused $refused) {
            self::assertSame('has_pairings', $refused->reason());
            self::assertStringContainsString('End the recurring item', $refused->getMessage());
        }
        self::assertInstanceOf(PlannedItem::class, $this->em->find(PlannedItem::class, $item->id()));
        self::assertSame(PairingState::Auto, $this->row('doc-1')->state());
    }

    public function testAConfirmedPairingAlsoRefusesTheDelete(): void
    {
        $item = $this->monthly('Cloud Host', '32.00');
        $this->document('doc-1', '99.00', '2026-05-18');
        $row = DocumentPairing::propose('received_document', 'doc-1', $this->supplier, [new PairingCandidate($item->id(), new \DateTimeImmutable('2026-05-20'))], $item, new \DateTimeImmutable('2026-11-17 10:00:00'));
        $row->confirm($item, new \DateTimeImmutable('2026-05-20'), $this->owner->user()->id(), new \DateTimeImmutable('2026-11-17 10:00:00'));
        $this->em->persist($row);
        $this->em->flush();

        $this->expectException(CashFlowRefused::class);
        $this->writes->delete(new DeletePlannedItemInput($item->id()));
    }

    public function testDeletingAnItemRemovesItFromTheProposalsAndTheDeclinedRows(): void
    {
        $a = $this->monthly('Cloud Host', '32.00');
        $b = $this->monthly('Voice API', '32.00');
        $this->document('doc-1', '32.00', '2026-05-18');
        $this->document('doc-2', '32.00', '2026-06-18');
        $this->considerAll();
        self::assertNull($this->row('doc-1')->plannedItemId());
        $declined = $this->row('doc-2');
        $declined->decline($this->owner->user()->id(), new \DateTimeImmutable('2026-11-17 10:00:00'));
        $this->em->flush();

        $this->writes->delete(new DeletePlannedItemInput($a->id()));

        self::assertNull($this->em->find(PlannedItem::class, $a->id()));
        $one = $this->row('doc-1');
        self::assertSame([PairingState::Auto, $b->id()], [$one->state(), $one->plannedItemId()], 'The one item left is the only candidate, so the document pairs on its own.');
        $declined = $this->row('doc-2');
        self::assertSame(PairingState::Declined, $declined->state());
        self::assertSame([$b->id()], array_map(static fn (PairingCandidate $candidate): string => $candidate->plannedItemId, $declined->candidates()));
    }

    public function testAProposalThatLosesItsLastCandidateIsRemoved(): void
    {
        $item = $this->monthly('Cloud Host', '32.00');
        $this->document('doc-1', '50.00', '2026-05-18');
        $this->considerAll();
        $row = $this->row('doc-1');
        self::assertSame([PairingState::Proposed, $item->id()], [$row->state(), $row->plannedItemId()]);

        $this->writes->delete(new DeletePlannedItemInput($item->id()));

        $this->em->clear();
        self::assertNull(self::getContainer()->get(CashFlowStore::class)->pairing('received_document', 'doc-1'));
        $events = $this->events('cash-flow.document_pairing.changed');
        $last = array_last($events);
        self::assertIsArray($last);
        self::assertArrayHasKey('state', $last);
        self::assertNull($last['state']);
    }

    public function testDeletingAnItemWithoutAnyPairingStillWorks(): void
    {
        $item = $this->monthly('Cloud Host', '32.00');

        self::assertSame($item->id(), $this->writes->delete(new DeletePlannedItemInput($item->id())));
        self::assertNull($this->em->find(PlannedItem::class, $item->id()));
    }

    public function testChangingTheCounterpartyOfAnItemLooksAtTheDocumentsOfBothCounterpartiesAgain(): void
    {
        $item = $this->monthly('Cloud Host', '32.00');
        $this->document('doc-1', '32.00', '2026-05-18');
        $this->document('doc-2', '32.00', '2026-05-18', $this->other);
        $this->considerAll();
        self::assertSame(PairingState::Auto, $this->row('doc-1')->state());
        self::assertNull($this->store()->pairing('received_document', 'doc-2'));

        $this->writes->change(new ChangePlannedItemInput($item->id(), counterpartyId: $this->other->id()));

        self::assertNull($this->store()->pairing('received_document', 'doc-1'), 'The old supplier\'s document no longer belongs to the item.');
        $moved = $this->row('doc-2');
        self::assertSame([PairingState::Auto, $item->id()], [$moved->state(), $moved->plannedItemId()]);
    }

    public function testSettingTheCounterpartyThroughTheContractChangesItAndPairsTheNewSuppliersDocuments(): void
    {
        $item = $this->monthly('Cloud Host', '32.00');
        $this->document('doc-2', '32.00', '2026-05-18', $this->other);
        $this->considerAll();
        self::assertNull($this->store()->pairing('received_document', 'doc-2'));

        // PlannedItemWrites implements the contract: it is the write path of the planned item: its own writes, with the fake documents of this test.
        $counterparties = $this->writes;
        $counterparties->set($item->id(), $this->other->id());

        $moved = $this->row('doc-2');
        self::assertSame([PairingState::Auto, $item->id()], [$moved->state(), $moved->plannedItemId()]);
        $this->em->clear();
        self::assertSame($this->other->id(), $this->em->find(PlannedItem::class, $item->id())?->counterparty()?->id());
        $this->expectException(CashFlowRefused::class);
        $counterparties->set($item->id(), 'not-a-contact');
    }

    public function testChangingTheAmountOrTheToleranceLooksAtTheDocumentsAgain(): void
    {
        $item = $this->monthly('Cloud Host', '32.00');
        $this->document('doc-1', '40.00', '2026-05-18');
        $this->considerAll();
        self::assertSame(PairingState::Proposed, $this->row('doc-1')->state());

        $this->writes->change(new ChangePlannedItemInput($item->id(), amount: '40.00'));

        self::assertSame(PairingState::Auto, $this->row('doc-1')->state());
    }

    public function testLeavingTheItemOutOfTheForecastDropsItsAutoPairings(): void
    {
        $item = $this->monthly('Cloud Host', '32.00');
        $this->document('doc-1', '32.00', '2026-05-18');
        $this->considerAll();

        $this->writes->change(new ChangePlannedItemInput($item->id(), included: false));

        self::assertNull($this->store()->pairing('received_document', 'doc-1'));
    }

    public function testAChangeThatMovesNothingDoesNotLookAtTheDocuments(): void
    {
        $item = $this->monthly('Cloud Host', '32.00');
        $this->document('doc-1', '32.00', '2026-05-18');
        $this->considerAll();
        $events = \count($this->events('cash-flow.document_pairing.changed'));
        $this->documents->remove('received_document', 'doc-1');
        $this->document('doc-3', '32.00', '2026-05-19');

        $this->writes->change(new ChangePlannedItemInput($item->id(), name: 'Cloud hosting'));

        self::assertNull($this->store()->pairing('received_document', 'doc-3'), 'Only a change that decides which documents belong looks at them.');
        self::assertCount($events, $this->events('cash-flow.document_pairing.changed'));
    }

    public function testASkippedOccurrenceLooksAtItsDocumentsAgain(): void
    {
        $item = $this->monthly('Cloud Host', '32.00');
        $this->document('doc-1', '32.00', '2026-05-18');
        $this->considerAll();
        self::assertSame(PairingState::Auto, $this->row('doc-1')->state());

        $this->writes->skip(new SkipPlannedOccurrenceInput($item->id(), '2026-05-20'));

        self::assertNull($this->store()->pairing('received_document', 'doc-1'));
    }

    public function testASkippedOccurrenceLeavesAConfirmedPairingAlone(): void
    {
        $item = $this->monthly('Cloud Host', '32.00');
        $this->document('doc-1', '32.00', '2026-05-18');
        $row = DocumentPairing::propose('received_document', 'doc-1', $this->supplier, [new PairingCandidate($item->id(), new \DateTimeImmutable('2026-05-20'))], $item, new \DateTimeImmutable('2026-11-17 10:00:00'));
        $row->confirm($item, new \DateTimeImmutable('2026-05-20'), $this->owner->user()->id(), new \DateTimeImmutable('2026-11-17 10:00:00'));
        $this->em->persist($row);
        $this->em->flush();

        $this->writes->skip(new SkipPlannedOccurrenceInput($item->id(), '2026-05-20'));

        self::assertSame(PairingState::Confirmed, $this->row('doc-1')->state());
    }

    public function testCreatingAnItemPairsTheDocumentsRecordedBefore(): void
    {
        $this->document('doc-1', '32.00', '2026-10-18');

        $item = $this->monthly('Cloud Host', '32.00');

        $row = $this->row('doc-1');
        self::assertSame([PairingState::Auto, $item->id(), '2026-10-20'], [$row->state(), $row->plannedItemId(), $row->occurrenceOn()?->format('Y-m-d')]);
    }

    public function testEndingAnItemDropsThePairingsOfTheOccurrencesAfterTheEnd(): void
    {
        $item = $this->monthly('Cloud Host', '32.00');
        $this->document('doc-1', '32.00', '2026-09-18');
        $this->document('doc-2', '32.00', '2026-11-18');
        $this->considerAll();
        self::assertSame(PairingState::Auto, $this->row('doc-2')->state());

        $this->writes->end(new EndPlannedItemInput($item->id(), '2026-10-31'));

        self::assertSame(PairingState::Auto, $this->row('doc-1')->state());
        self::assertNull($this->store()->pairing('received_document', 'doc-2'));
    }

    public function testDeletingAnItemLeavesThePairingsOfOtherItemsAlone(): void
    {
        $a = $this->monthly('Cloud Host', '32.00');
        $b = $this->writes->create(new CreatePlannedItemInput('out', 'Voice API', 'software', '32.00', '2026-05-20', 'USD', 'month', 1, false, null, null, $this->other->id(), true));
        $this->document('doc-1', '50.00', '2026-05-18');
        $this->document('doc-2', '32.00', '2026-05-18', $this->other);
        $this->considerAll();
        $before = $this->row('doc-2');
        self::assertSame([PairingState::Auto, $b->id()], [$before->state(), $before->plannedItemId()]);
        $updated = $before->updatedAt();
        $this->clock->modify('+2 hours');

        $this->writes->delete(new DeletePlannedItemInput($a->id()));

        self::assertEquals($updated, $this->row('doc-2')->updatedAt(), 'A pairing that does not list the item is not touched.');
    }

    public function testADeclinedRowThatListedOnlyTheDeletedItemStaysWithoutCandidates(): void
    {
        $item = $this->monthly('Cloud Host', '32.00');
        $this->document('doc-1', '50.00', '2026-05-18');
        $this->considerAll();
        $declined = $this->row('doc-1');
        $declined->decline($this->owner->user()->id(), new \DateTimeImmutable('2026-11-17 10:00:00'));
        $this->em->flush();

        $this->writes->delete(new DeletePlannedItemInput($item->id()));

        $row = $this->row('doc-1');
        self::assertSame(PairingState::Declined, $row->state());
        self::assertSame([], $row->candidates());
    }

    public function testEveryProposalThatListsOnlyTheDeletedItemIsRemoved(): void
    {
        $item = $this->monthly('Cloud Host', '32.00');
        $this->document('doc-1', '50.00', '2026-05-18');
        $this->document('doc-2', '50.00', '2026-06-18');
        $this->document('doc-3', '50.00', '2026-07-18');
        $this->considerAll();

        $this->writes->delete(new DeletePlannedItemInput($item->id()));

        $this->em->clear();
        self::assertSame([], $this->store()->pairings());
    }

    public function testEveryProposalThatKeepsOneCandidateLooksAtItsDocumentAgain(): void
    {
        $a = $this->monthly('Cloud Host', '32.00');
        $b = $this->monthly('Voice API', '32.00');
        $this->document('doc-1', '32.00', '2026-05-18');
        $this->document('doc-2', '32.00', '2026-06-18');
        $this->considerAll();
        self::assertSame(PairingState::Proposed, $this->row('doc-2')->state());

        $this->writes->delete(new DeletePlannedItemInput($a->id()));

        self::assertSame([PairingState::Auto, $b->id()], [$this->row('doc-1')->state(), $this->row('doc-1')->plannedItemId()]);
        self::assertSame([PairingState::Auto, $b->id()], [$this->row('doc-2')->state(), $this->row('doc-2')->plannedItemId()]);
        $events = array_values(array_filter($this->events('cash-flow.document_pairing.changed'), static fn (array $event): bool => 'doc-1' === $event['subject_id']));
        self::assertCount(3, $events);
        self::assertSame(['proposed', $b->id(), '2026-05-20', null], [$events[1]['state'], $events[1]['planned_item_id'], $events[1]['occurrence_on'], $events[1]['previous_planned_item_id']], 'The proposal that keeps one candidate points at it.');
        self::assertSame(['auto', $b->id(), '2026-05-20', $b->id()], [$events[2]['state'], $events[2]['planned_item_id'], $events[2]['occurrence_on'], $events[2]['previous_planned_item_id']]);
    }

    public function testChangingTheToleranceAloneLooksAtTheDocumentsAgain(): void
    {
        $item = $this->monthly('Cloud Host', '32.00');
        $this->document('doc-1', '33.00', '2026-05-18');
        $this->considerAll();
        self::assertSame(PairingState::Proposed, $this->row('doc-1')->state());

        $this->writes->change(new ChangePlannedItemInput($item->id(), matchTolerance: '1.00'));

        self::assertSame(PairingState::Auto, $this->row('doc-1')->state());
    }

    public function testChangingTheCurrencyAloneLooksAtTheDocumentsAgain(): void
    {
        $item = $this->monthly('Cloud Host', '32.00');
        $this->document('doc-1', '32.00', '2026-05-18');
        $this->considerAll();
        self::assertSame(PairingState::Auto, $this->row('doc-1')->state());

        $this->writes->change(new ChangePlannedItemInput($item->id(), currency: 'EUR'));

        self::assertSame(PairingState::Proposed, $this->row('doc-1')->state());
    }

    public function testChangingTheFirstDayAloneLooksAtTheDocumentsAgain(): void
    {
        $item = $this->monthly('Cloud Host', '32.00');
        $this->document('doc-1', '32.00', '2026-05-18');
        $this->considerAll();
        self::assertSame('2026-05-20', $this->row('doc-1')->occurrenceOn()?->format('Y-m-d'));

        $this->writes->change(new ChangePlannedItemInput($item->id(), on: '2026-05-25'));

        self::assertSame('2026-05-25', $this->row('doc-1')->occurrenceOn()->format('Y-m-d'));
    }

    public function testChangingTheUnitOfTheScheduleAloneLooksAtTheDocumentsAgain(): void
    {
        $item = $this->monthly('Cloud Host', '32.00');
        // A Monday: June for a monthly item, the week of Wednesday 3 June for a weekly one.
        $this->document('doc-1', '32.00', '2026-06-01');
        $this->considerAll();
        self::assertSame('2026-06-20', $this->row('doc-1')->occurrenceOn()?->format('Y-m-d'));

        $this->writes->change(new ChangePlannedItemInput($item->id(), repeat: 'week'));

        self::assertSame('2026-06-03', $this->row('doc-1')->occurrenceOn()->format('Y-m-d'));
    }

    private function monthly(string $name, string $amount): PlannedItem
    {
        return $this->writes->create(new CreatePlannedItemInput('out', $name, 'software', $amount, '2026-05-20', 'USD', 'month', 1, false, null, null, $this->supplier->id(), true));
    }

    private function document(string $id, string $amount, string $on, ?Party $supplier = null): PairableDocument
    {
        return $this->documents->add(new PairableDocument(new FlowSubject('received_document', $id), ($supplier ?? $this->supplier)->id(), FlowDirection::Out, Money::of($amount, Currency::of('USD')), new \DateTimeImmutable($on)));
    }

    private function considerAll(): void
    {
        $pairing = new Pairing(
            self::getContainer()->get(CashFlowStore::class), self::getContainer()->get(PlannedItemVatRule::class), self::getContainer()->get(PlannedOccurrences::class), self::getContainer()->get(InstanceClock::class),
            self::getContainer()->get(TransactionalWork::class), self::getContainer()->get(EventRecorder::class), self::getContainer()->get(ClockInterface::class), [$this->documents],
        );
        foreach ($this->documents->since(new \DateTimeImmutable('2000-01-01')) as $document) {
            $pairing->consider($document);
        }
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

    private function party(string $name, string $number): Party
    {
        $party = new Party(PartyKind::Company, $name, new RegistrationNumber('CZ', $number), null, '');
        $this->em->persist($party);
        $this->em->flush();

        return $party;
    }
}
