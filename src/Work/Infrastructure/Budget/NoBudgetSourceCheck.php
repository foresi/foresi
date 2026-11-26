<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Work\Infrastructure\Budget;

use Foresi\Work\Application\BudgetSourceCheck;

/**
 * No activity is the source of a budget change when Activities is not
 * installed and nothing answers which activities are linked to a project: a
 * change names its source only when it can be shown to belong to the project.
 */
final readonly class NoBudgetSourceCheck implements BudgetSourceCheck
{
    public function isOnProject(string $activityId, string $projectId): bool
    {
        return false;
    }
}
