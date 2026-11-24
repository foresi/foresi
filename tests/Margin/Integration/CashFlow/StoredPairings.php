<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\Margin\Integration\CashFlow;

use Foresi\CashFlow\Contract\FlowSubject;
use Foresi\CashFlow\Contract\PairedOccurrence;
use Foresi\CashFlow\Contract\PairedSubject;
use Foresi\CashFlow\Contract\PairingState;
use Foresi\CashFlow\Contract\PlannedPairings;
use Foresi\Margin\Integration\CashFlow\CashFlowPairings;

/**
 * The pairings as Cash flow has stored them, which may lag behind its events.
 */
final class StoredPairings implements PlannedPairings
{
    private const string ITEM = '0197a1de-0000-7000-8000-0000000000e1';

    /** @var list<array{FlowSubject, string}> */
    public array $pairs = [];

    public function pair(string $documentId, string $on): void
    {
        $this->pairs[] = [new FlowSubject(CashFlowPairings::RECEIVED_DOCUMENT, $documentId), $on];
    }

    public function of(FlowSubject $subject): ?PairedOccurrence
    {
        foreach ($this->pairs as [$paired, $on]) {
            if ($paired->type === $subject->type && $paired->id === $subject->id) {
                return new PairedOccurrence(self::ITEM, new \DateTimeImmutable($on), PairingState::Auto);
            }
        }

        return null;
    }

    public function ofOccurrence(string $plannedItemId, \DateTimeImmutable $on): array
    {
        return array_values(array_map(static fn (array $pair): FlowSubject => $pair[0], array_filter($this->pairs, static fn (array $pair): bool => self::ITEM === $plannedItemId && $pair[1] === $on->format('Y-m-d'))));
    }

    public function ofItem(string $plannedItemId, \DateTimeImmutable $fromDay): array
    {
        return array_values(array_map(static fn (array $pair): PairedSubject => new PairedSubject($pair[0], new \DateTimeImmutable($pair[1])), array_filter($this->pairs, static fn (array $pair): bool => self::ITEM === $plannedItemId && $pair[1] >= $fromDay->format('Y-m-d'))));
    }
}
