<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Margin\Application;

/**
 * The mapping of a planned item over time: its versions, latest month first.
 */
final readonly class PlannedAllocationsView
{
    /**
     * @param list<PlannedAllocationVersionView> $versions
     */
    public function __construct(
        public string $plannedItemId,
        public string $name,
        public array $versions,
    ) {
    }
}
