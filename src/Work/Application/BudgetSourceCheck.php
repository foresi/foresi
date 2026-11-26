<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Work\Application;

/**
 * Whether an activity may be the source of a change of a project's budget:
 * it must be linked to the project, as the e-mail that approved the hours is.
 * Activities keeps the links and answers for them when it is installed
 * (ActivityBudgetSourceCheck); without it no activity is a source
 * (NoBudgetSourceCheck).
 */
interface BudgetSourceCheck
{
    public function isOnProject(string $activityId, string $projectId): bool;
}
