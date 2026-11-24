<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Margin\Application;

/**
 * What stands behind one cell of the table: the revenue documents and the
 * cost shares that add up to its figures.
 */
final readonly class MarginCellView
{
    /**
     * @param list<MarginCellItem>       $revenue    by tax point, then number
     * @param list<MarginCellItem>       $costs      the highest amount first
     * @param list<UnrebilledOccurrence> $unrebilled the paid costs of the client this month that were not rebilled, oldest first
     */
    public function __construct(
        public string $row,
        public ?string $name,
        public string $month,
        public MarginFigures $figures,
        public array $revenue,
        public array $costs,
        public array $unrebilled = [],
    ) {
    }

    public function label(): string
    {
        return MarginRowView::labelOf($this->row, $this->name);
    }
}
