<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Shared\Infrastructure\Http;

use App\Shared\Domain\Currency;
use App\Shared\Domain\Money;

/**
 * An amount of a form field that is not negative, such as a rate: read as people
 * type it (TypedNumber) and written back for the field in the user's language,
 * without grouping. Integer minor units throughout, never a float.
 */
final class TypedAmount
{
    private const string AMOUNT = '/^\d{1,13}(?:\.\d{1,2})?$/D';

    /**
     * @return Money|false|null null for a blank field, false for text that is no amount
     */
    public static function read(string $typed, Currency $currency, string $locale): Money|false|null
    {
        if ('' === trim($typed)) {
            return null;
        }
        $decimal = TypedNumber::decimal($typed, $locale);

        return 1 === preg_match(self::AMOUNT, $decimal) ? Money::of($decimal, $currency) : false;
    }

    /**
     * "1500,00" in Czech, "1500.00" in English.
     */
    public static function field(?Money $money, string $locale): string
    {
        if (!$money instanceof Money) {
            return '';
        }
        $unit = 10 ** $money->currency()->minorUnits();
        $minor = abs($money->minor());
        $separator = new \NumberFormatter($locale, \NumberFormatter::DECIMAL)->getSymbol(\NumberFormatter::DECIMAL_SEPARATOR_SYMBOL);

        return \sprintf('%s%d%s%0'.$money->currency()->minorUnits().'d', $money->isNegative() ? '-' : '', intdiv($minor, $unit), $separator, $minor % $unit);
    }
}
