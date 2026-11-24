<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Margin\Application;

/**
 * One version of a planned item's mapping: the month it applies from
 * (YYYY-MM), the mapping or null where it ends the mapping, and whether it
 * is the version in force this month.
 */
final readonly class PlannedAllocationVersionView
{
    public function __construct(
        public string $fromMonth,
        public ?MappingView $mapping,
        public bool $current,
        public \DateTimeImmutable $createdAt,
        public string $createdBy,
    ) {
    }
}
