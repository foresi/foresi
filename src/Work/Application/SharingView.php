<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Work\Application;

/**
 * The sharing dialog of a project, a section or a task: whether it is closed
 * (a project only to the people chosen, a section or a task private), who it
 * is shared with, and who else of the organisation it can be shared with.
 */
final readonly class SharingView
{
    /**
     * @param list<SharedPerson>        $people     by name
     * @param list<Choice>              $candidates the active people of the organisation it is not shared with, owners left out as they see everything; by name
     * @param list<SharingHistoryEntry> $history    of a project or a section, oldest first; a task tells it in its own history
     */
    public function __construct(
        public ShareKind $kind,
        public string $id,
        public string $name,
        public string $projectId,
        public string $projectName,
        public bool $closed,
        public array $people,
        public array $candidates,
        public array $history,
    ) {
    }
}
