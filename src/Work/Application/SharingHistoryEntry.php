<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Work\Application;

/**
 * One step of who sees a project or a section: it was closed to the people
 * chosen or opened again, or shared with people or taken back from them.
 */
final readonly class SharingHistoryEntry
{
    /**
     * @param string|null  $userName     null when no person did it
     * @param bool|null    $closed       true when only the people chosen see it since, false when it was opened, null when that did not change
     * @param list<string> $sharedWith   names
     * @param list<string> $unsharedWith names
     */
    public function __construct(
        public \DateTimeImmutable $occurredAt,
        public ?string $userName,
        public ?bool $closed,
        public array $sharedWith = [],
        public array $unsharedWith = [],
    ) {
    }
}
