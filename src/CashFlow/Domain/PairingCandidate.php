<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\CashFlow\Domain;

/**
 * One planned occurrence a document may belong to.
 */
final readonly class PairingCandidate
{
    public function __construct(
        public string $plannedItemId,
        public \DateTimeImmutable $occurrenceOn,
    ) {
    }

    /**
     * @return array{planned_item_id: string, occurrence_on: string}
     */
    public function toArray(): array
    {
        return ['planned_item_id' => $this->plannedItemId, 'occurrence_on' => $this->occurrenceOn->format('Y-m-d')];
    }

    /**
     * @param array{planned_item_id: string, occurrence_on: string} $row
     */
    public static function fromArray(array $row): self
    {
        return new self($row['planned_item_id'], new \DateTimeImmutable($row['occurrence_on'])->setTime(0, 0));
    }
}
