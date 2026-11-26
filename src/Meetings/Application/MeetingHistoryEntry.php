<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Meetings\Application;

/**
 * One step of a meeting's history: who did it and when, and what changed.
 */
final readonly class MeetingHistoryEntry
{
    public const string CREATED = 'created';
    public const string CHANGED = 'changed';
    public const string TRASHED = 'trashed';
    public const string RESTORED = 'restored';

    /**
     * @param list<string> $fields   the translation keys of the fields changed, a custom field's label among them
     * @param list<string> $added    the names of the attendees added
     * @param list<string> $removed  the names of the attendees removed
     * @param int          $linked   how many records were linked
     * @param int          $unlinked how many links were removed
     */
    public function __construct(
        public \DateTimeImmutable $at,
        public ?string $userName,
        public string $kind,
        public array $fields = [],
        public array $added = [],
        public array $removed = [],
        public int $linked = 0,
        public int $unlinked = 0,
    ) {
    }
}
