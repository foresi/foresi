<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Work\Application;

use Foresi\Identity\Domain\Membership;
use Foresi\Work\Domain\Task;
use Foresi\Work\Domain\TaskStar;

/**
 * The stars of My priorities (plan 37.2).
 */
interface TaskStars
{
    /**
     * A person's stars in their order, every one of them: also those of a task in the trash,
     * finished or no longer seen, which keep their place.
     *
     * @return list<TaskStar>
     */
    public function of(Membership $membership): array;

    public function star(Task $task, Membership $membership): ?TaskStar;

    /**
     * @return list<TaskStar> everyone's stars on the task
     */
    public function onTask(Task $task): array;

    public function add(TaskStar $star): void;

    public function remove(TaskStar $star): void;
}
