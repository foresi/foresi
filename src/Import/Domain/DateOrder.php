<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Import\Domain;

/** The order of day, month and year in a date that is not written in ISO form. */
enum DateOrder
{
    case DayMonthYear;
    case MonthDayYear;
    case YearMonthDay;
}
