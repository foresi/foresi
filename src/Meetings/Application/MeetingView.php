<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Meetings\Application;

use Foresi\Meetings\Domain\Meeting;

/**
 * A meeting as a person sees it: its attendees by name and the linked records
 * they see, with what they may do with it.
 */
final readonly class MeetingView
{
    /**
     * @param list<array{id: string, name: string}>                              $attendees by name
     * @param list<array{kind: string, id: string, label: string, url: ?string}> $links     the records the person sees
     */
    public function __construct(
        public Meeting $meeting,
        public array $attendees,
        public array $links,
        public bool $trashable,
    ) {
    }
}
