<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Timesheet\Application;

use Foresi\Timesheet\Domain\ProjectTimeSetting;
use Foresi\Work\Domain\Project;

/**
 * Where the time settings of projects are kept.
 */
interface ProjectTimeSettingStore
{
    /**
     * @return ProjectTimeSetting|null null for a project whose settings were never changed
     */
    public function setting(Project $project): ?ProjectTimeSetting;

    public function add(ProjectTimeSetting $setting): void;

    /**
     * Whether time logged on the project is billable unless said otherwise.
     */
    public function billsByDefault(Project $project): bool;
}
