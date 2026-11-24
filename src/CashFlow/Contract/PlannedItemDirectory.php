<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\CashFlow\Contract;

/**
 * Looks planned items up for a document module.
 */
interface PlannedItemDirectory
{
    public function one(string $id): ?PlannedItemSummary;

    /**
     * The day of the occurrence of the item whose period holds $day, or null when it has none there (not recurring, skipped, past its end).
     */
    public function occurrenceHolding(string $id, \DateTimeImmutable $day): ?\DateTimeImmutable;

    /**
     * The planned items money goes out for (planned costs), by name, for a module that lets a person pick one.
     *
     * @return list<PlannedItemSummary>
     */
    public function costs(): array;
}
