<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\CashFlow\Application;

use Foresi\Audit\Application\AuditActor;
use Foresi\CashFlow\Contract\Event\DocumentPairingChanged;
use Foresi\CashFlow\Contract\FlowSubject;
use Foresi\CashFlow\Contract\PairingState;
use Foresi\CashFlow\Domain\DocumentPairing;
use Foresi\CashFlow\Domain\PairingCandidate;
use Foresi\Instance\Application\InstanceClock;
use Foresi\Party\Application\TransactionalWork;
use Foresi\Shared\Application\Event\EventRecorder;
use Psr\Clock\ClockInterface;

/**
 * The decisions of a person about which planned occurrence a document belongs
 * to (ADR 0032): pair it, which confirms it, and unpair it, which declines it,
 * so Cash flow never pairs it on its own again. Each runs in one unit of work
 * with its cash-flow.document_pairing.changed event, and only the owners and
 * members of the organisation decide. The other documents that share a period
 * with the occurrences touched are looked at again.
 */
final readonly class PairingWrites
{
    public function __construct(
        private CashFlowAccess $access,
        private CashFlowStore $store,
        private Pairing $pairing,
        private PlannedOccurrences $occurrences,
        private TransactionalWork $transaction,
        private EventRecorder $events,
        private AuditActor $actor,
        private InstanceClock $instanceClock,
        private ClockInterface $clock,
    ) {
    }

    /**
     * @throws CashFlowRefused not_found, direction_mismatch, not_an_occurrence
     */
    public function pair(PairDocumentInput $input): DocumentPairing
    {
        $this->access->denyUnlessStaff();

        return $this->transaction->run(function () use ($input): DocumentPairing {
            $item = $this->store->plannedItem($input->plannedItemId) ?? throw CashFlowRefused::notFound('The organisation has no planned item with this id. Find it with foresi_list_planned_items.');
            $document = $this->pairing->document($input->subjectType, $input->subjectId) ?? throw CashFlowRefused::notFound('No document of this type and id is known to Cash flow, or it is in the trash.');
            if ($document->direction !== $item->direction()) {
                throw CashFlowRefused::invalid('direction_mismatch', 'plannedItemId', 'The document and the planned item move money in different directions; pair a received document with a planned cost.');
            }
            $on = PlannedItemRules::day($input->occurrenceOn, $this->instanceClock->timezone(), 'occurrenceOn');
            if (!$this->occurrences->occursOn($item, $on)) {
                throw CashFlowRefused::invalid('not_an_occurrence', 'occurrenceOn', 'The planned item does not fall on this day, or the occurrence is skipped or after its end; use a day it falls on, as the forecast lists it.');
            }
            $now = $this->clock->now();
            $userId = $this->actor->userId() ?? throw CashFlowRefused::forbidden();
            $row = $this->store->pairing($input->subjectType, $input->subjectId);
            $before = $row instanceof DocumentPairing ? $this->facts($row) : null;
            $touched = $row instanceof DocumentPairing ? $row->candidates() : [];
            if ($row instanceof DocumentPairing) {
                $row->confirm($item, $on, $userId, $now);
            } else {
                $counterparty = null === $document->counterpartyId ? null : $this->access->counterparty($document->counterpartyId);
                $row = DocumentPairing::auto($input->subjectType, $input->subjectId, $counterparty, $item, $on, $now);
                $row->confirm($item, $on, $userId, $now);
                $this->store->add($row);
            }
            $this->record($row, $before);
            $touched[] = new PairingCandidate($item->id(), $on);
            $this->pairing->reconsiderNeighbours(new FlowSubject($input->subjectType, $input->subjectId), $row->counterpartyId(), $touched);

            return $row;
        });
    }

    /**
     * @throws CashFlowRefused not_found
     */
    public function unpair(UnpairDocumentInput $input): DocumentPairing
    {
        $this->access->denyUnlessStaff();

        return $this->transaction->run(function () use ($input): DocumentPairing {
            $row = $this->store->pairing($input->subjectType, $input->subjectId) ?? throw CashFlowRefused::notFound('The document has no pairing with a planned occurrence. Find the pairings with foresi_list_pairings.');
            if (PairingState::Declined === $row->state()) {
                return $row;
            }
            $userId = $this->actor->userId() ?? throw CashFlowRefused::forbidden();
            $before = $this->facts($row);
            // The occurrence a pairing points at is always one of its candidates, so this holds the periods it touched.
            $touched = $row->candidates();
            $row->decline($userId, $this->clock->now());
            $this->record($row, $before);
            $this->pairing->reconsiderNeighbours(new FlowSubject($input->subjectType, $input->subjectId), $row->counterpartyId(), $touched);

            return $row;
        });
    }

    /**
     * @param array{string, ?string, ?string}|null $before state, planned item and occurrence day; null for a new row
     */
    private function record(DocumentPairing $row, ?array $before): void
    {
        $after = $this->facts($row);
        if ($before !== $after) {
            $this->events->record(new DocumentPairingChanged($row->subjectType(), $row->subjectId(), $after[0], $after[1], $after[2], $before[1] ?? null));
        }
    }

    /**
     * @return array{string, ?string, ?string}
     */
    private function facts(DocumentPairing $row): array
    {
        return [$row->state()->value, $row->plannedItemId(), $row->occurrenceOn()?->format('Y-m-d')];
    }
}
