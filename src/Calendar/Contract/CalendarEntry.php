<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Calendar\Contract;

/**
 * One thing in a person's calendar feed, as a source gives it: no description,
 * place or attendees, only what a calendar app needs to show it and link back.
 *
 * A timed entry starts and ends at instants, in any time zone: the feed writes
 * them in UTC, so each keeps its wall-clock time across a change of summer time.
 * An all-day entry starts on the date of $start and lasts to the date of $end, that
 * day included, or one day when $end is null; the time of day is ignored.
 */
final readonly class CalendarEntry
{
    /**
     * @param string                  $uid          stable across fetches and unique to the instance, from CalendarUids::of()
     * @param \DateTimeImmutable|null $end          null for a timed entry without a duration, or a single day
     * @param \DateTimeImmutable      $lastModified when the thing behind the entry last changed
     * @param string|null             $url          an absolute link to the thing in Foresi
     */
    public function __construct(
        public string $uid,
        public string $title,
        public \DateTimeImmutable $start,
        public ?\DateTimeImmutable $end,
        public bool $allDay,
        public \DateTimeImmutable $lastModified,
        public ?string $url,
    ) {
    }
}
