<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Work\Contract;

use Foresi\Shared\Contract\Refused;

/**
 * Tasks made by another module, such as an action item of a meeting's
 * minutes: the same task the task screen makes, under the person signed in,
 * with Work's own checks, its work.task.created event and its audit.
 */
interface TaskIntake
{
    /**
     * Joins the caller's transaction when one is open, so the task and what the
     * caller records with it are saved together or not at all.
     *
     * @param string|null             $responsibleId the membership of an active person of the organisation who sees the task, or null for nobody
     * @param \DateTimeImmutable|null $dueOn         the day it is due, or null for none
     * @param bool                    $private       seen only by the people it is shared with, its creator kept on it
     *
     * @return string the id of the task
     *
     * @throws Refused with Work's reasons, such as "unknown" for a project the person does not see, "archived", "name" or "responsible"
     */
    public function create(string $projectId, string $title, ?string $responsibleId = null, ?\DateTimeImmutable $dueOn = null, bool $private = false): string;
}
