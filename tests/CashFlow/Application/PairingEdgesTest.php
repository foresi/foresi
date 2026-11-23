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
use Foresi\CashFlow\Domain\PlannedItem;
use Foresi\Instance\Application\InstanceClock;
use Foresi\Party\Application\TransactionalWork;
use Foresi\Party\Domain\Party;
use Foresi\Party\Domain\PartyKind;
use Foresi\Party\Domain\RegistrationNumber;
use Foresi\Shared\Application\Event\EventRecorder;
use Foresi\Shared\Domain\Currency;
use Foresi\Shared\Domain\Money;
use Foresi\Tests\CashFlow\Support\CashFlowTestCase;
use Foresi\Tests\CashFlow\Support\FakePairableDocuments;
use Psr\Clock\ClockInterface;

/**
 * The edges of the pairing rules: what is written before a document is forgotten, which items are candidates,
 * which documents share a period, and what the event says. Today is 17 November 2026 in Prague.
 */
final class PairingEdgesTest extends CashFlowTestCase
{
    private const string EVENT = 'cash-flow.document_pairing.changed';

    private FakePairableDocuments $documents;
    private Pairing $pairing;
    private Party $supplier;
    private Party $other;

    protected function setUp(): void
    {
        parent::setUp();
        $this->supplier = $this->party('Cloud Host s.r.o.', '11111111');
        $this->other = $this->party('Voice API s.r.o.', '22222222');
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

    public function testForgettingADocumentFirstWritesWhatTheCallerChanged(): void
    {
        $item = $this->monthly('Cloud Host');
        $row = DocumentPairing::auto('received_document', 'doc-1', $this->supplier, $item, new \DateTimeImmutable('2026-05-20'), new \DateTimeImmutable('2026-11-17 10:00:00'));
        $this->store()->add($row);

        $this->pairing->forget(new FlowSubject('received_document', 'doc-1'));

        $this->em->clear();
        self::assertNotInstanceOf(DocumentPairing::class, $this->store()->pairing('received_document', 'doc-1'));
        $events = $this->events(self::EVENT);
        self::assertCount(1, $events);
        self::assertNull($events[0]['state']);
        self::assertSame($item->id(), $events[0]['previous_planned_item_id']);
    }

    public function testTheDocumentsOfSeveralPairingsAreAllReturned(): void
    {
        $this->monthly('Cloud Host');
        $this->document('doc-1', '32.00', '2026-05-18');
        $this->document('doc-2', '32.00', '2026-06-18');
        $this->pairing->consider($this->documents->one('received_document', 'doc-1') ?? self::fail());
        $this->pairing->consider($this->documents->one('received_document', 'doc-2') ?? self::fail());

        $found = $this->pairing->documents($this->store()->pairings());

        self::assertEqualsCanonicalizing(['received_document:doc-1', 'received_document:doc-2'], array_keys($found));
    }

    public function testOnlyAnIncludedRecurringItemOfTheSameSupplierAndDirectionIsACandidate(): void
    {
        $this->planned(on: '2026-05-20', amount: '32.00', repeat: 'month', name: 'Left out', currency: 'USD', included: false, counterpartyId: $this->supplier->id());
        $this->planned(on: '2026-05-20', amount: '32.00', name: 'One-off', currency: 'USD', counterpartyId: $this->supplier->id());
        $this->planned(on: '2026-05-20', amount: '32.00', direction: 'in', repeat: 'month', name: 'Money in', currency: 'USD', counterpartyId: $this->supplier->id());
        $this->planned(on: '2026-05-20', amount: '32.00', repeat: 'month', name: 'Voice API', currency: 'USD', counterpartyId: $this->other->id());
        $item = $this->monthly('Cloud Host');

        $this->pairing->consider($this->document('doc-1', '32.00', '2026-05-18'));

        $row = $this->row('doc-1');
        self::assertSame(PairingState::Auto, $row->state());
        self::assertSame($item->id(), $row->plannedItemId());
        self::assertCount(1, $row->candidates());
    }

    public function testADocumentThatIsGoneFromItsSourceDoesNotKeepTheOthersApart(): void
    {
        $item = $this->monthly('Cloud Host');
        $this->pairing->consider($this->document('doc-1', '32.00', '2026-05-10'));
        $this->pairing->consider($this->document('doc-2', '32.00', '2026-05-12'));
        self::assertSame(PairingState::Proposed, $this->row('doc-1')->state());

        // The source still lists the document that is going: only the subject tells Cash flow it is gone.
        $this->pairing->forget(new FlowSubject('received_document', 'doc-2'));

        self::assertSame(PairingState::Auto, $this->row('doc-1')->state());
        self::assertSame($item->id(), $this->row('doc-1')->plannedItemId());
    }

    public function testTheEventNamesTheItemADocumentLeft(): void
    {
        $a = $this->monthly('Cloud Host');
        $document = $this->document('doc-1', '32.00', '2026-05-18');
        $this->pairing->consider($document);
        $b = $this->monthly('Voice API');

        $this->pairing->consider($document);

        $events = $this->events(self::EVENT);
        self::assertCount(2, $events);
        self::assertSame(['proposed', null, null, $a->id()], [$events[1]['state'], $events[1]['planned_item_id'], $events[1]['occurrence_on'], $events[1]['previous_planned_item_id']]);
        self::assertNotSame($a->id(), $b->id());
    }

    private function monthly(string $name): PlannedItem
    {
        return $this->writes()->create(new CreatePlannedItemInput('out', $name, 'software', '32.00', '2026-05-20', 'USD', 'month', 1, false, null, null, $this->supplier->id(), true));
    }

    private function document(string $id, string $amount, string $on): PairableDocument
    {
        return $this->documents->add(new PairableDocument(new FlowSubject('received_document', $id), $this->supplier->id(), FlowDirection::Out, Money::of($amount, Currency::USD), new \DateTimeImmutable($on)));
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
