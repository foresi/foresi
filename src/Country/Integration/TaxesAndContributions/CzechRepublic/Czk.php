<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Country\Integration\TaxesAndContributions\CzechRepublic;

use Foresi\Shared\Domain\Currency;
use Foresi\Shared\Domain\Money;

/**
 * Exact arithmetic on CZK in minor units (haléře), with the roundings the
 * statutes ask: insurance bases and contributions up to whole CZK, the tax
 * base down to whole hundreds, the tax up to whole CZK. A rate or share is a
 * decimal string in per cent, such as "29.2".
 */
final class Czk
{
    public const int UNIT = 100;

    /**
     * @throws \InvalidArgumentException for a value that is not a plain decimal
     */
    public static function minorOf(string $decimal): int
    {
        [$numerator, $denominator] = self::ratio($decimal);

        return intdiv($numerator * self::UNIT, $denominator);
    }

    /**
     * The per cent of an amount, rounded up to whole CZK.
     */
    public static function percentUp(int $minor, string $percent): int
    {
        [$numerator, $denominator] = self::ratio($percent);

        return self::ceilDiv($minor * $numerator, $denominator * 100 * self::UNIT) * self::UNIT;
    }

    /**
     * The per cent of an amount, rounded down to whole CZK.
     */
    public static function percentDown(int $minor, string $percent): int
    {
        [$numerator, $denominator] = self::ratio($percent);

        return self::floorDiv($minor * $numerator, $denominator * 100 * self::UNIT) * self::UNIT;
    }

    /**
     * The per cent of an amount, exact to the haléř (half up).
     */
    public static function percent(int $minor, string $percent): int
    {
        [$numerator, $denominator] = self::ratio($percent);
        $divisor = $denominator * 100;

        return self::floorDiv(2 * $minor * $numerator + $divisor, 2 * $divisor);
    }

    /**
     * An amount times a whole factor, divided by another, rounded up to whole
     * CZK: such as 117,521 x 11 / 12.
     */
    public static function fractionUp(int $minor, int $times, int $by): int
    {
        return self::ceilDiv($minor * $times, $by * self::UNIT) * self::UNIT;
    }

    public static function upToCzk(int $minor): int
    {
        return self::ceilDiv($minor, self::UNIT) * self::UNIT;
    }

    public static function downToHundreds(int $minor): int
    {
        return self::floorDiv($minor, 100 * self::UNIT) * 100 * self::UNIT;
    }

    /**
     * The amount as a decimal string, such as "17139.00".
     */
    public static function text(int $minor): string
    {
        $sign = $minor < 0 ? '-' : '';

        return \sprintf('%s%d.%02d', $sign, intdiv(abs($minor), self::UNIT), abs($minor) % self::UNIT);
    }

    /**
     * The amount in whole CZK with thousands apart, for an English sentence.
     */
    public static function words(int $minor): string
    {
        return number_format(intdiv($minor, self::UNIT), 0, '.', ',').' CZK';
    }

    public static function money(int $minor): Money
    {
        return Money::ofMinor($minor, Currency::CZK);
    }

    /**
     * @return array{int, int} the decimal as a numerator and a power of ten
     *
     * @throws \InvalidArgumentException for a value that is not a plain decimal
     */
    private static function ratio(string $decimal): array
    {
        if (1 !== preg_match('/^(-?)([0-9]+)(?:\.([0-9]+))?\z/', $decimal, $parts)) {
            throw new \InvalidArgumentException(\sprintf('"%s" is not a decimal.', $decimal));
        }
        $fraction = $parts[3] ?? '';
        $numerator = (int) ($parts[2].$fraction);

        return ['-' === $parts[1] ? -$numerator : $numerator, 10 ** \strlen($fraction)];
    }

    private static function ceilDiv(int $dividend, int $divisor): int
    {
        return -self::floorDiv(-$dividend, $divisor);
    }

    private static function floorDiv(int $dividend, int $divisor): int
    {
        $quotient = intdiv($dividend, $divisor);

        return (0 !== $dividend % $divisor && ($dividend < 0) !== ($divisor < 0)) ? $quotient - 1 : $quotient;
    }
}
