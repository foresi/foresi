<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Timesheet\Application;

use Foresi\Identity\Application\OwnerCheck;
use Foresi\Shared\Domain\Money;
use Foresi\Timesheet\Domain\BudgetCounting;
use Foresi\Timesheet\Domain\ProjectTimeSetting;
use Foresi\Work\Application\VisibleWork;
use Foresi\Work\Application\WorkRefused;
use Foresi\Work\Application\WorkUnit;
use Foresi\Work\Domain\Project;

/**
 * The time settings of a project: whether its time is billable by default
 * (a custom field of the project, ProjectTimeFields), its own hourly rate,
 * which only an owner sees and sets, and which of its time uses its budget.
 */
final readonly class ProjectTimeSettings
{
    public function __construct(
        private ProjectTimeSettingStore $settings,
        private VisibleWork $work,
        private OwnerCheck $owner,
        private WorkUnit $unit,
    ) {
    }

    public function billsByDefault(Project $project): bool
    {
        return $this->settings->billsByDefault($project);
    }

    public function hourlyRate(Project $project): ?Money
    {
        return $this->settings->setting($project)?->hourlyRate();
    }

    public function budgetCounting(Project $project): BudgetCounting
    {
        return $this->settings->setting($project)?->budgetCounting() ?? BudgetCounting::All;
    }

    /**
     * The project's own hourly rate, null for none; only an owner sets it.
     *
     * @throws TimeRefused|WorkRefused
     * @throws \DomainException        for a negative rate
     */
    public function setHourlyRate(string $projectId, ?Money $rate): void
    {
        $project = $this->work->managedProject($projectId);
        if (!$this->owner->isOwner()) {
            throw new TimeRefused('not_allowed');
        }
        $this->unit->run(fn () => $this->of($project)->changeHourlyRate($rate));
    }

    /**
     * The settings of the project to change, added when it had none.
     */
    public function of(Project $project): ProjectTimeSetting
    {
        $setting = $this->settings->setting($project);
        if (!$setting instanceof ProjectTimeSetting) {
            $setting = new ProjectTimeSetting($project);
            $this->settings->add($setting);
        }

        return $setting;
    }
}
