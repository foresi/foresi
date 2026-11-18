<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Instance;

use Foresi\Instance\Application\Schedule\RunOperatorTask;
use Foresi\Instance\Application\Schedule\RunScheduledJob;
use Foresi\Shared\Infrastructure\Module\ModuleBundle;

/**
 * The installation itself: its clock and zone, the schedule of timed work
 * and the health check.
 */
final class InstanceBundle extends ModuleBundle
{
    protected function messageRouting(): array
    {
        return [
            RunScheduledJob::class => 'async',
            RunOperatorTask::class => 'async',
        ];
    }
}
