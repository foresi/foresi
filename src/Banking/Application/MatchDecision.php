<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Banking\Application;

use App\Banking\Domain\UnmatchedReason;
use App\Invoicing\Application\AllocationRequest;

/**
 * What the matcher decided for a movement: how to split it over invoices, or why
 * it stays unmatched and what a person may pair it with instead.
 */
final readonly class MatchDecision
{
    /**
     * @param list<AllocationRequest> $allocations
     * @param list<Candidate>         $suggestions
     */
    private function __construct(
        public array $allocations,
        public ?UnmatchedReason $reason,
        public array $suggestions,
    ) {
    }

    /**
     * @param non-empty-list<AllocationRequest> $allocations
     */
    public static function pair(array $allocations): self
    {
        return new self($allocations, null, []);
    }

    /**
     * @param list<Candidate> $suggestions oldest due first
     */
    public static function unmatched(UnmatchedReason $reason, array $suggestions): self
    {
        return new self([], $reason, $suggestions);
    }

    public function pairs(): bool
    {
        return [] !== $this->allocations;
    }
}
