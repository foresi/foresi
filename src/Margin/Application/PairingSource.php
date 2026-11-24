<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Margin\Application;

use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/**
 * Where Margin learns which planned item and occurrence a cost document is
 * paired with. Cash flow is optional for Margin, so the source lives in
 * Integration/CashFlow and is collected by this tag (ADR 0033); without it
 * no document is paired and no planned item is known.
 */
#[AutoconfigureTag(self::TAG)]
interface PairingSource
{
    public const string TAG = 'app.margin_pairing_source';

    /**
     * The occurrence the document is paired with (auto or confirmed), or null.
     */
    public function pairingOf(string $documentId): ?CostPairing;

    /**
     * The ids of the cost documents paired with the occurrence.
     *
     * @return list<string>
     */
    public function documentsOfOccurrence(string $plannedItemId, \DateTimeImmutable $occurrenceOn): array;

    /**
     * The ids of the cost documents paired with occurrences of the item on or after the first day of the month.
     *
     * @return list<string>
     */
    public function documentsOfItemFrom(string $plannedItemId, \DateTimeImmutable $fromMonth): array;

    /**
     * The planned item as a mapping needs it, or null when this source has none with this id.
     */
    public function item(string $plannedItemId): ?PlannedCostItem;

    /**
     * The day of the occurrence of the item whose period holds the day, or null when it has none there.
     */
    public function occurrenceHolding(string $plannedItemId, \DateTimeImmutable $day): ?\DateTimeImmutable;

    /**
     * The planned items money goes out for, by name, for a person to pick one.
     *
     * @return list<PlannedCostItem>
     */
    public function costItems(): array;

    /**
     * The days of the occurrences on or after the day that have at least one cost document paired with them, once each, oldest first.
     *
     * @return list<\DateTimeImmutable>
     */
    public function occurrencesWithDocumentsFrom(string $plannedItemId, \DateTimeImmutable $fromDay): array;
}
