<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Meetings\Application;

use Foresi\Shared\Application\View\ViewFilter;

/**
 * What a search of the meetings asks for: the conditions of a view of the list,
 * a text in the title or the minutes, a series, an attendee, a range of days and a
 * linked record, newest first unless asked otherwise. The trash holds only the
 * meetings in the trash; every other search leaves them out.
 */
final readonly class MeetingSearch
{
    public const int MAX_TEXT = 100;

    public function __construct(
        public ViewFilter $filter = new ViewFilter([]),
        public string $text = '',
        public ?string $seriesId = null,
        public ?string $attendeeId = null,
        public ?\DateTimeImmutable $from = null,
        public ?\DateTimeImmutable $to = null,
        public ?string $linkKind = null,
        public ?string $linkId = null,
        public bool $oldestFirst = false,
        public bool $trash = false,
        public int $limit = 500,
        public ?string $afterId = null,
    ) {
    }
}
