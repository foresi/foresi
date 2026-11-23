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
use Foresi\Shared\Domain\Id;

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

    /**
     * The same, for a state and a planned item as a client writes them.
     *
     * @return list<DocumentPairing>
     *
     * @throws CashFlowRefused forbidden, or invalid state_invalid, planned_item_invalid
     */
    public function list(?string $state, ?string $plannedItemId): array
    {
        $this->access->denyUnlessStaff();
        $parsed = null === $state ? null : PairingState::tryFrom($state) ?? throw CashFlowRefused::invalid('state_invalid', 'state', 'The state is one of: '.implode(', ', array_map(static fn (PairingState $case): string => $case->value, PairingState::cases())).'.');
        if (null !== $plannedItemId && !Id::isValid($plannedItemId)) {
            throw CashFlowRefused::invalid('planned_item_invalid', 'plannedItemId', 'The planned item id is a UUID. Find it with foresi_list_planned_items.');
        }

        return $this->store->pairings($parsed, $plannedItemId);
    }

    private function pairs(DocumentPairing $pairing): bool
    {
        return \in_array($pairing->state(), [PairingState::Auto, PairingState::Confirmed], true);
    }
}
