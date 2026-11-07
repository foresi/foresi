<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Work\Application;

/**
 * One step in the life of a task: one action of a person on it.
 */
final readonly class TaskHistoryEntry
{
    public const string CREATED = 'created';
    public const string CHANGED = 'changed';

    /**
     * @param string|null  $userName        null when no person did it
     * @param list<string> $fields          translation keys of the plain fields that changed, work.task.field.<field>
     * @param bool         $statusChanged   whether the status changed, from $statusFrom to $statusTo
     * @param bool         $reassigned      whether the responsible person changed, from $assignedFrom to $assignedTo; null is nobody
     * @param list<string> $watchersAdded   names
     * @param list<string> $watchersRemoved names
     * @param list<string> $labelsAdded     names
     * @param list<string> $labelsRemoved   names
     */
    public function __construct(
        public \DateTimeImmutable $occurredAt,
        public ?string $userName,
        public string $event,
        public array $fields = [],
        public bool $statusChanged = false,
        public ?string $statusFrom = null,
        public ?string $statusTo = null,
        public bool $reassigned = false,
        public ?string $assignedFrom = null,
        public ?string $assignedTo = null,
        public array $watchersAdded = [],
        public array $watchersRemoved = [],
        public array $labelsAdded = [],
        public array $labelsRemoved = [],
    ) {
    }
}
