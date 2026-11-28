<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Shared\Infrastructure\Http;

use Foresi\Shared\Domain\Currency;
use Foresi\Shared\Domain\Money;
use Foresi\Shared\Domain\MoneyFormat;

/**
 * An amount of a form field that is not negative, such as a rate: read as people
 * type it (TypedNumber) and written back for the field in the user's language,
 * without grouping. Integer minor units throughout, never a float.
 */
final class TypedAmount
{
    /**
     * @return Money|false|null null for a blank field, false for text that is no amount
     */
    public static function read(string $typed, Currency $currency, string $locale): Money|false|null
    {
        if ('' === trim($typed)) {
            return null;
        }
        $decimal = TypedNumber::decimal($typed, $locale);

        // As many decimals as the currency has: "12.345" for BHD, none for JPY.
        $digits = $currency->minorUnits();
        $pattern = 0 === $digits ? '/^\d{1,15}$/D' : \sprintf('/^\d{1,13}(?:\.\d{1,%d})?$/D', $digits);

        return 1 === preg_match($pattern, $decimal) ? Money::of($decimal, $currency) : false;
    }

    /**
     * "1500,00" in Czech, "1500.00" in English.
     */
    public static function field(?Money $money, string $locale): string
    {
        if (!$money instanceof Money) {
            return '';
        }

        return MoneyFormat::field($money, $locale);
    }
}
