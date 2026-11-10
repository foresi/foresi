<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Timesheet\Domain;

/**
 * Where an hourly rate came from, in the order the levels are asked.
 */
enum RateSource: string
{
    case Project = 'project';
    case Client = 'client';
    case Person = 'person';
}
