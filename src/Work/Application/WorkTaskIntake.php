<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Work\Application;

use Foresi\Work\Contract\TaskIntake;

/**
 * The contract over Work's own use cases: ManageTasks makes the task and
 * ManageSharing makes it private, in one unit, so every check, the event and
 * the audit are theirs.
 */
final readonly class WorkTaskIntake implements TaskIntake
{
    public function __construct(
        private ManageTasks $tasks,
        private ManageSharing $sharing,
        private Projects $store,
        private WorkUnit $unit,
    ) {
    }

    public function create(string $projectId, string $title, ?string $responsibleId = null, ?\DateTimeImmutable $dueOn = null, bool $private = false): string
    {
        return $this->unit->run(function () use ($projectId, $title, $responsibleId, $dueOn, $private): string {
            try {
                $task = $this->tasks->create($projectId, $title, responsibleId: $responsibleId, dueOn: $dueOn);
            } catch (WorkRefused $refused) {
                throw 'name' === $refused->reason ? $refused->about('title') : $refused;
            }
            if ($private) {
                // Saved, so making it private finds it as the task page does.
                $this->store->flush();
                $this->sharing->makeTaskPrivate($task->id(), true);
            }

            return $task->id();
        });
    }
}
