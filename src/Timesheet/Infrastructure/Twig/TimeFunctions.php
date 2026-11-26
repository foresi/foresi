<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Timesheet\Infrastructure\Twig;

use Foresi\Timesheet\Application\TimeEntryRow;
use Foresi\Timesheet\Application\TimeQueries;
use Twig\Attribute\AsTwigFunction;

/**
 * Time in templates: the timer of the person signed in, which the shell shows
 * over every page. A duration is Ui's |duration.
 */
final readonly class TimeFunctions
{
    public function __construct(private TimeQueries $time)
    {
    }

    /**
     * The person's running timer, or null.
     */
    #[AsTwigFunction('running_timer')]
    public function runningTimer(): ?TimeEntryRow
    {
        return $this->time->running();
    }
}
