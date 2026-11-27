<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Meetings\Contract;

use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/**
 * The records of some kinds a meeting can be about, the same shape as
 * Foresi\Activities\Contract\ActivityTarget. Meetings answers for a project and a
 * contact itself; another module answers for its own records in its
 * Integration/Meetings. A link to a record that goes away stays, and shows
 * nothing. A link never widens who sees the meeting.
 */
#[AutoconfigureTag(self::TAG)]
interface MeetingTarget
{
    public const string TAG = 'foresi.meetings.target';

    /**
     * @return list<string> the record kinds this target answers for, such as ["contact"]
     */
    public function kinds(): array;

    /** Whether the record exists and the person may see it. */
    public function canSee(string $kind, string $id): bool;

    /** Whether the person may link a meeting to the record. */
    public function canLink(string $kind, string $id): bool;

    /** The name to show for the record, or null when it is gone or hidden. */
    public function label(string $kind, string $id): ?string;

    /** The screen of the record, or null when it is gone or hidden. */
    public function url(string $kind, string $id): ?string;
}
