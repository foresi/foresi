<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Work\Application;

use App\Work\Domain\ProjectStatus;
use App\Work\Domain\Section;
use App\Work\Domain\Task;

/**
 * Dense positions from 0: only what actually moves is written, so a reorder
 * touches, and audits, no more rows than it must.
 */
final class Positions
{
    /**
     * Puts the item at the index among the others, then numbers them all.
     *
     * @param list<ProjectStatus|Section|Task> $items in order, with or without the item
     */
    public static function place(array $items, ProjectStatus|Section|Task $item, int $index): void
    {
        $others = array_values(array_filter($items, static fn (ProjectStatus|Section|Task $other): bool => $other->id() !== $item->id()));
        array_splice($others, max(0, min($index, \count($others))), 0, [$item]);
        self::renumber($others);
    }

    /**
     * @param list<ProjectStatus|Section|Task> $items in order
     */
    public static function renumber(array $items): void
    {
        foreach ($items as $position => $item) {
            if ($item->position() !== $position) {
                $item->moveTo($position);
            }
        }
    }
}
