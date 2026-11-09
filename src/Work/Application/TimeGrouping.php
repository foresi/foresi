<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Work\Application;

/**
 * What the totals of a time report add up by. By task, time without a task
 * is a line of its project.
 */
enum TimeGrouping: string
{
    case Project = 'project';
    case Person = 'person';
    case Task = 'task';
}
