<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Meetings\Application;

use Foresi\Meetings\Domain\MeetingSeries;

/**
 * A series as a person sees it: its members by name, how many meetings it has
 * outside the trash and the day of the last, and whether they may change it.
 */
final readonly class SeriesView
{
    /**
     * @param list<array{id: string, name: string, default_attendee: bool}> $members by name
     */
    public function __construct(
        public MeetingSeries $series,
        public array $members,
        public int $meetings,
        public ?\DateTimeImmutable $lastHeldOn,
        public bool $changeable,
    ) {
    }
}
