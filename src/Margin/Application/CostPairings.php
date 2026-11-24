<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Margin\Application;

use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;

/**
 * The pairings of cost documents with planned items, from the sources that
 * are installed, asked in turn. With none (Cash flow is not installed) no
 * document is paired and no planned item is known: the null case.
 */
final readonly class CostPairings
{
    /**
     * @param iterable<PairingSource> $sources
     */
    public function __construct(#[AutowireIterator(PairingSource::TAG)] private iterable $sources = [])
    {
    }

    public function of(string $documentId): ?CostPairing
    {
        foreach ($this->sources as $source) {
            $pairing = $source->pairingOf($documentId);
            if ($pairing instanceof CostPairing) {
                return $pairing;
            }
        }

        return null;
    }

    /**
     * @return list<string>
     */
    public function documentsOfOccurrence(string $plannedItemId, \DateTimeImmutable $occurrenceOn): array
    {
        $ids = [];
        foreach ($this->sources as $source) {
            $ids = [...$ids, ...$source->documentsOfOccurrence($plannedItemId, $occurrenceOn)];
        }

        return array_values(array_unique($ids));
    }

    /**
     * @return list<string>
     */
    public function documentsOfItemFrom(string $plannedItemId, \DateTimeImmutable $fromMonth): array
    {
        $ids = [];
        foreach ($this->sources as $source) {
            $ids = [...$ids, ...$source->documentsOfItemFrom($plannedItemId, $fromMonth)];
        }

        return array_values(array_unique($ids));
    }

    public function item(string $plannedItemId): ?PlannedCostItem
    {
        foreach ($this->sources as $source) {
            $item = $source->item($plannedItemId);
            if ($item instanceof PlannedCostItem) {
                return $item;
            }
        }

        return null;
    }

    /**
     * @return list<PlannedCostItem> by name, each item once
     */
    public function costItems(): array
    {
        $items = [];
        foreach ($this->sources as $source) {
            foreach ($source->costItems() as $item) {
                $items[$item->id] = $item;
            }
        }
        usort($items, static fn (PlannedCostItem $a, PlannedCostItem $b): int => strcasecmp($a->name, $b->name));

        return $items;
    }

    public function occurrenceHolding(string $plannedItemId, \DateTimeImmutable $day): ?\DateTimeImmutable
    {
        foreach ($this->sources as $source) {
            $occurrence = $source->occurrenceHolding($plannedItemId, $day);
            if ($occurrence instanceof \DateTimeImmutable) {
                return $occurrence;
            }
        }

        return null;
    }

    /**
     * @return list<\DateTimeImmutable> oldest first, each day once
     */
    public function occurrencesWithDocumentsFrom(string $plannedItemId, \DateTimeImmutable $fromDay): array
    {
        $days = [];
        foreach ($this->sources as $source) {
            foreach ($source->occurrencesWithDocumentsFrom($plannedItemId, $fromDay) as $day) {
                $days[$day->format('Y-m-d')] = $day;
            }
        }
        ksort($days);

        return array_values($days);
    }
}
