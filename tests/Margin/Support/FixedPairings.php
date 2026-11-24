<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\Margin\Support;

use Foresi\Margin\Application\CostPairing;
use Foresi\Margin\Application\PairingSource;
use Foresi\Margin\Application\PlannedCostItem;

/**
 * Planned items and pairings a test names, so Margin is tested without Cash flow.
 */
final class FixedPairings implements PairingSource
{
    /** @var array<string, PlannedCostItem> */
    public array $items = [];

    /** @var array<string, CostPairing> by document id, in the order paired */
    public array $pairings = [];

    public function pair(string $documentId, string $plannedItemId, string $occurrenceOn): void
    {
        $this->pairings[$documentId] = new CostPairing($plannedItemId, new \DateTimeImmutable($occurrenceOn));
    }

    public function unpair(string $documentId): void
    {
        unset($this->pairings[$documentId]);
    }

    public function pairingOf(string $documentId): ?CostPairing
    {
        return $this->pairings[$documentId] ?? null;
    }

    public function documentsOfOccurrence(string $plannedItemId, \DateTimeImmutable $occurrenceOn): array
    {
        return $this->documents(static fn (CostPairing $pairing): bool => $pairing->plannedItemId === $plannedItemId && $pairing->occurrenceOn->format('Y-m-d') === $occurrenceOn->format('Y-m-d'));
    }

    public function documentsOfItemFrom(string $plannedItemId, \DateTimeImmutable $fromMonth): array
    {
        return $this->documents(static fn (CostPairing $pairing): bool => $pairing->plannedItemId === $plannedItemId && $pairing->occurrenceOn->format('Y-m-d') >= $fromMonth->format('Y-m-01'));
    }

    public function item(string $plannedItemId): ?PlannedCostItem
    {
        return $this->items[$plannedItemId] ?? null;
    }

    /**
     * @param callable(CostPairing): bool $matches
     *
     * @return list<string>
     */
    private function documents(callable $matches): array
    {
        return array_keys(array_filter($this->pairings, $matches));
    }
}
