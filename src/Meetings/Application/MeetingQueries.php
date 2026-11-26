<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Meetings\Application;

use Foresi\Meetings\Domain\Meeting;

/**
 * The meetings a search finds among those the person sees (MeetingVisibility).
 */
interface MeetingQueries
{
    /**
     * @return list<Meeting> by the day held, then the time, newest first unless the search asks for the oldest
     */
    public function search(MeetingSearch $search): array;

    /**
     * How many meetings the person sees are held today, none in the trash.
     */
    public function todayCount(): int;
}
