<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Calendar\Contract;

use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/**
 * A module that plans things in time for a person, such as their activities,
 * implements this in its Integration/Calendar/ (ADR 0033) to put them in that
 * person's calendar feeds. Calendar never knows the module. A source that throws
 * is logged and left out of the feed, which still answers with the others.
 */
#[AutoconfigureTag(self::TAG)]
interface CalendarEntrySource
{
    public const string TAG = 'foresi.calendar.entry_source';

    /**
     * The source's key, stable and unique, such as "activities.planned"; it is part of the uid of each entry.
     */
    public function key(): string;

    /**
     * The entries of the person that fall in [$from, $to): those that start before $to and end on or after $from.
     *
     * @return iterable<CalendarEntry>
     */
    public function entriesFor(string $userId, \DateTimeImmutable $from, \DateTimeImmutable $to): iterable;
}
