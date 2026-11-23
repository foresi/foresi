<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\CashFlow\Application;

use Foresi\Audit\Application\AuditActor;
use Foresi\Audit\Domain\ActorKind;
use Foresi\CashFlow\Application\CashFlowAccess;
use Foresi\CashFlow\Application\CashFlowRefused;
use Foresi\CashFlow\Application\CashFlowStore;
use Foresi\CashFlow\Application\PairDocumentInput;
use Foresi\CashFlow\Application\Pairing;
use Foresi\CashFlow\Application\PairingWrites;
use Foresi\CashFlow\Application\PlannedItemVatRule;
use Foresi\CashFlow\Application\PlannedOccurrences;
use Foresi\CashFlow\Application\UnpairDocumentInput;
use Foresi\CashFlow\Contract\FlowDirection;
use Foresi\CashFlow\Contract\FlowSubject;
use Foresi\CashFlow\Contract\PairableDocument;
use Foresi\CashFlow\Contract\PairingState;
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
 * A person pairs and unpairs a document: who may, what happens to the other documents of the period, and
 * what a repeated decision does not change. Today is 17 November 2026 in Prague.
 */
final class PairingWritesTest extends CashFlowTestCase
{
    private FakePairableDocuments $documents;
    private Pairing $pairing;
    private Party $supplier;
    private PlannedItem $item;

    protected function setUp(): void
    {
        parent::setUp();
        $this->supplier = new Party(PartyKind::Company, 'Cloud Host s.r.o.', new RegistrationNumber('CZ', '11111111'), null, '');
        $this->em->persist($this->supplier);
        $this->em->flush();
        $this->documents = new FakePairableDocuments();
        $c = self::getContainer();
        $this->pairing = new Pairing(
            $c->get(CashFlowStore::class), $c->get(PlannedItemVatRule::class), $c->get(PlannedOccurrences::class), $c->get(InstanceClock::class),
            $c->get(TransactionalWork::class), $c->get(EventRecorder::class), $c->get(ClockInterface::class), [$this->documents],
        );
        $this->item = $this->planned(on: '2026-05-20', amount: '32.00', repeat: 'month', name: 'Cloud Host', currency: 'USD', counterpartyId: $this->supplier->id());
    }

    public function testAGuestCannotPairOrUnpair(): void
    {
        $this->document('doc-1', $this->supplier->id());
        $this->signIn($this->guest);

        foreach ([
            fn (): \Foresi\CashFlow\Domain\DocumentPairing => $this->pairingWrites(null)->pair(new PairDocumentInput('received_document', 'doc-1', $this->item->id(), '2026-05-20')),
            fn (): \Foresi\CashFlow\Domain\DocumentPairing => $this->pairingWrites(null)->unpair(new UnpairDocumentInput('received_document', 'doc-1')),
        ] as $attempt) {
            try {
                $attempt();
                self::fail('A guest decided.');
            } catch (CashFlowRefused $refused) {
                self::assertSame('forbidden', $refused->reason());
            }
        }
    }

    public function testNobodyDecidesWithoutAUser(): void
    {
        $this->document('doc-1', $this->supplier->id());
        $nobody = new class implements AuditActor {
            public function userId(): ?string
            {
                return null;
            }

            public function kind(): ActorKind
            {
                return ActorKind::System;
            }
        };
        $this->pairing->consider($this->documents->one('received_document', 'doc-1') ?? self::fail());

        foreach ([
            fn (): \Foresi\CashFlow\Domain\DocumentPairing => $this->pairingWrites($nobody)->pair(new PairDocumentInput('received_document', 'doc-1', $this->item->id(), '2026-05-20')),
            fn (): \Foresi\CashFlow\Domain\DocumentPairing => $this->pairingWrites($nobody)->unpair(new UnpairDocumentInput('received_document', 'doc-1')),
        ] as $attempt) {
            try {
                $attempt();
                self::fail('A decision was made without a user.');
            } catch (CashFlowRefused $refused) {
                self::assertSame('forbidden', $refused->reason());
            }
        }
        $this->em->clear();
        self::assertSame(PairingState::Auto, $this->store()->pairing('received_document', 'doc-1')?->state());
    }

    public function testADocumentWithoutACounterpartyCanBePaired(): void
    {
        $this->document('doc-1', null);

        $row = $this->pairingWrites(null)->pair(new PairDocumentInput('received_document', 'doc-1', $this->item->id(), '2026-05-20'));

        self::assertSame(PairingState::Confirmed, $row->state());
        self::assertNull($row->counterpartyId());
        self::assertSame($this->item->id(), $row->plannedItemId());
    }

    public function testPairingADocumentLooksAtTheOtherDocumentsOfThePeriodAgain(): void
    {
        $this->document('doc-1', $this->supplier->id(), '2026-05-10');
        $this->pairing->consider($this->documents->one('received_document', 'doc-1') ?? self::fail());
        $this->document('doc-2', $this->supplier->id(), '2026-05-12');
        $this->em->clear();
        self::assertSame(PairingState::Auto, $this->store()->pairing('received_document', 'doc-1')?->state());

        $this->pairingWrites(null)->pair(new PairDocumentInput('received_document', 'doc-2', $this->item->id(), '2026-05-20'));

        $this->em->clear();
        self::assertSame(PairingState::Proposed, $this->store()->pairing('received_document', 'doc-1')->state());
    }

    public function testUnpairingADeclinedDocumentChangesNothing(): void
    {
        $this->document('doc-1', $this->supplier->id());
        $this->pairing->consider($this->documents->one('received_document', 'doc-1') ?? self::fail());
        $writes = $this->pairingWrites(null);
        $first = $writes->unpair(new UnpairDocumentInput('received_document', 'doc-1'));
        $decidedAt = $first->decidedAt();
        self::assertSame(PairingState::Declined, $first->state());
        $this->clock->modify('+1 hour');

        $again = $writes->unpair(new UnpairDocumentInput('received_document', 'doc-1'));

        self::assertSame(PairingState::Declined, $again->state());
        self::assertEquals($decidedAt, $again->decidedAt());
        self::assertCount(2, $this->events('cash-flow.document_pairing.changed'));
    }

    private function pairingWrites(?AuditActor $actor): PairingWrites
    {
        $c = self::getContainer();

        return new PairingWrites(
            $c->get(CashFlowAccess::class), $c->get(CashFlowStore::class), $this->pairing, $c->get(PlannedOccurrences::class), $c->get(TransactionalWork::class),
            $c->get(EventRecorder::class), $actor ?? $c->get(AuditActor::class), $c->get(InstanceClock::class), $c->get(ClockInterface::class),
        );
    }

    private function document(string $id, ?string $counterpartyId, string $on = '2026-05-18'): PairableDocument
    {
        return $this->documents->add(new PairableDocument(new FlowSubject('received_document', $id), $counterpartyId, FlowDirection::Out, Money::of('32.00', Currency::USD), new \DateTimeImmutable($on)));
    }

    private function store(): CashFlowStore
    {
        return self::getContainer()->get(CashFlowStore::class);
    }
}
