<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Purchasing\Application;

use App\Shared\Domain\Money;

/**
 * An amount as the events of the module state it: a decimal with the minor
 * units of its currency, such as "1210.00" or "-50.00".
 */
final class EventAmount
{
    public static function of(Money $money): string
    {
        $unit = 10 ** $money->currency()->minorUnits();
        $minor = abs($money->minor());

        return \sprintf('%s%d.%0'.$money->currency()->minorUnits().'d', $money->isNegative() ? '-' : '', intdiv($minor, $unit), $minor % $unit);
    }
}
