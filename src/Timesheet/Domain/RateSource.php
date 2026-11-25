<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Timesheet\Domain;

/**
 * Where an hourly rate came from, in the order the levels are asked.
 */
enum RateSource: string
{
    /** The rate a module sets for the whole project (ProjectRateSource): the hourly deal line it is linked to. */
    case DealLine = 'deal_line';
    case Project = 'project';
    case Client = 'client';
    case Person = 'person';
}
