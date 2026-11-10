<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Timesheet\Domain;

/**
 * What one line of an invoice of time holds (Harvest, Clockify): the time of a
 * project, of a task of it, of a person on it, or a single entry. Time without
 * a task goes on a line of its own, named after the project.
 */
enum LineGrouping: string
{
    case Project = 'project';
    case Task = 'task';
    case Person = 'person';
    case Entry = 'entry';
}
