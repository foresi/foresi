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
        $places = $money->currency()->minorUnits();
        $unit = 10 ** $places;
        $minor = abs($money->minor());

        return 0 === $places
            ? \sprintf('%s%d', $money->isNegative() ? '-' : '', $minor)
            : \sprintf('%s%d.%0'.$places.'d', $money->isNegative() ? '-' : '', intdiv($minor, $unit), $minor % $unit);
    }
}
