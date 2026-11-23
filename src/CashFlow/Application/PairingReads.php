<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\CashFlow\Application;

use Foresi\CashFlow\Contract\FlowSubject;
use Foresi\CashFlow\Contract\PairedOccurrence;
use Foresi\CashFlow\Contract\PairingState;
use Foresi\CashFlow\Contract\PlannedPairings;
use Foresi\CashFlow\Domain\DocumentPairing;

/**
 * The pairings of documents with planned occurrences: what other modules read
 * through Foresi\CashFlow\Contract\PlannedPairings (auto and confirmed only),
 * and the list the API and MCP give, only to the owners and members.
 */
final readonly class PairingReads implements PlannedPairings
{
    public function __construct(private CashFlowAccess $access, private CashFlowStore $store)
    {
    }

    public function of(FlowSubject $subject): ?PairedOccurrence
    {
        $pairing = $this->store->pairing($subject->type, $subject->id);
        if (!$pairing instanceof DocumentPairing || !$this->pairs($pairing)) {
            return null;
        }
        $itemId = $pairing->plannedItemId();
        $on = $pairing->occurrenceOn();

        return null === $itemId || !$on instanceof \DateTimeImmutable ? null : new PairedOccurrence($itemId, $on, $pairing->state());
    }

    public function ofOccurrence(string $plannedItemId, \DateTimeImmutable $on): array
    {
        return array_values(array_map(
            static fn (DocumentPairing $pairing): FlowSubject => new FlowSubject($pairing->subjectType(), $pairing->subjectId()),
            array_filter($this->store->pairingsOfOccurrence($plannedItemId, $on), $this->pairs(...)),
        ));
    }

    /**
     * @return list<DocumentPairing> oldest first; with a planned item, the pairings that point at it
     *
     * @throws CashFlowRefused
     */
    public function all(?PairingState $state = null, ?string $plannedItemId = null): array
    {
        $this->access->denyUnlessStaff();

        return $this->store->pairings($state, $plannedItemId);
    }

    private function pairs(DocumentPairing $pairing): bool
    {
        return \in_array($pairing->state(), [PairingState::Auto, PairingState::Confirmed], true);
    }
}
