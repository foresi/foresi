<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Import\Domain;

enum DateOrder: string
{
    case DayMonthYear = 'day_month_year';
    case MonthDayYear = 'month_day_year';
    case YearMonthDay = 'year_month_day';
}
