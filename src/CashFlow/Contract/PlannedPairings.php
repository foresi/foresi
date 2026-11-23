<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\CashFlow\Contract;

/**
 * What a document module reads of the pairings: where a document is paired,
 * and which documents are paired with an occurrence. Only auto and confirmed
 * pairings count.
 */
interface PlannedPairings
{
    public function of(FlowSubject $subject): ?PairedOccurrence;

    /**
     * @return list<FlowSubject> the documents paired with the occurrence, with no URL
     */
    public function ofOccurrence(string $plannedItemId, \DateTimeImmutable $on): array;
}
