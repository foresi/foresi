<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Activities\Application;

use Foresi\Activities\Domain\Activity;

/**
 * The order of a record's activities: what is still planned on top, the
 * soonest first, then what is done, the latest first.
 */
final class Timeline
{
    /**
     * @param list<Activity> $activities
     *
     * @return list<Activity>
     */
    public static function order(array $activities): array
    {
        $planned = array_filter($activities, static fn (Activity $activity): bool => !$activity->isDone());
        $done = array_filter($activities, static fn (Activity $activity): bool => $activity->isDone());
        usort($planned, static fn (Activity $a, Activity $b): int => [self::plannedFor($a), $a->id()] <=> [self::plannedFor($b), $b->id()]);
        usort($done, static fn (Activity $a, Activity $b): int => [$b->doneAt(), $b->id()] <=> [$a->doneAt(), $a->id()]);

        return [...$planned, ...$done];
    }

    /** When a planned activity is due: its time, or the start of its day. */
    private static function plannedFor(Activity $activity): string
    {
        return ($activity->plannedAt() ?? $activity->plannedOn() ?? $activity->createdAt())->format('Y-m-d H:i:s');
    }
}
