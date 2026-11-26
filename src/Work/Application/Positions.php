<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Work\Application;

use Foresi\Work\Domain\ProjectStatus;
use Foresi\Work\Domain\Section;
use Foresi\Work\Domain\Task;
use Foresi\Work\Domain\TaskStar;

/**
 * Dense positions from 0: only what actually moves is written, so a reorder
 * touches, and audits, no more rows than it must.
 */
final class Positions
{
    /**
     * Puts the item at the index among the others, then numbers them all.
     *
     * @param list<ProjectStatus|Section|Task|TaskStar> $items in order, with or without the item
     */
    public static function place(array $items, ProjectStatus|Section|Task|TaskStar $item, int $index): void
    {
        $others = array_filter($items, static fn (ProjectStatus|Section|Task|TaskStar $other): bool => $other->id() !== $item->id());
        array_splice($others, max(0, min($index, \count($others))), 0, [$item]);
        self::renumber($others);
    }

    /**
     * @param list<ProjectStatus|Section|Task|TaskStar> $items in order
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
