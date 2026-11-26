<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Work\Integration\Activities;

use Foresi\Activities\Contract\ActivityLinks;
use Foresi\Work\Application\BudgetSourceCheck;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;

/**
 * An activity is the source of a budget change when it is linked to the
 * project, as the e-mail that approved the hours is, and the person adding the
 * change sees it. Replaces NoBudgetSourceCheck when Activities is installed.
 */
#[AsAlias(BudgetSourceCheck::class)]
final readonly class ActivityBudgetSourceCheck implements BudgetSourceCheck
{
    public function __construct(private ActivityLinks $links)
    {
    }

    public function isOnProject(string $activityId, string $projectId): bool
    {
        return $this->links->isLinked($activityId, ProjectActivityTarget::KIND, $projectId);
    }
}
