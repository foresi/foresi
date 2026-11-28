<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Shared\Domain;

/**
 * Money as text, with as many decimals as its currency has (ADR 0049): "1210.50"
 * for EUR, "1210" for JPY, "12.345" for BHD. Integer minor units throughout,
 * never a float. The one place that knows how many digits follow the separator.
 */
final class MoneyFormat
{
    private function __construct()
    {
    }

    /**
     * A plain decimal with a dot and no grouping, for files and machines: "-1210.50".
     */
    public static function decimal(Money $money): string
    {
        return self::compose($money, '.', static fn (int $units): string => (string) $units);
    }

    /**
     * A decimal in the language of $locale, without grouping, for a form field: "1210,50" in Czech.
     */
    public static function field(Money $money, string $locale): string
    {
        return self::compose($money, self::separator($locale), static fn (int $units): string => (string) $units);
    }

    /**
     * Grouped in the language of $locale, without the unit: "1 210,50" in Czech, "1,210.50" in English.
     */
    public static function grouped(Money $money, string $locale): string
    {
        $formatter = new \NumberFormatter($locale, \NumberFormatter::DECIMAL);

        return self::compose($money, $formatter->getSymbol(\NumberFormatter::DECIMAL_SEPARATOR_SYMBOL), static fn (int $units): string => (string) $formatter->format($units, \NumberFormatter::TYPE_INT64));
    }

    /**
     * The grouped amount and its unit: "1 210,50 Kč" in Czech, "1,210.50 CZK" in English.
     */
    public static function shown(Money $money, string $locale): string
    {
        return \sprintf("%s\u{a0}%s", self::grouped($money, $locale), self::unit($money->currency(), $locale));
    }

    /**
     * The symbol of the currency in the language of $locale, as ICU gives it: "Kč" for CZK in Czech, "€" for EUR.
     */
    public static function unit(Currency $currency, string $locale): string
    {
        $formatter = new \NumberFormatter($locale.'@currency='.$currency->code(), \NumberFormatter::CURRENCY);
        $symbol = $formatter->getSymbol(\NumberFormatter::CURRENCY_SYMBOL);

        return '' === $symbol ? $currency->code() : $symbol;
    }

    /**
     * Ten to the power of the currency's minor units: 100 for EUR, 1 for JPY, 1000 for BHD.
     */
    public static function factor(Currency $currency): int
    {
        return 10 ** $currency->minorUnits();
    }

    private static function separator(string $locale): string
    {
        return new \NumberFormatter($locale, \NumberFormatter::DECIMAL)->getSymbol(\NumberFormatter::DECIMAL_SEPARATOR_SYMBOL);
    }

    /**
     * @param \Closure(int): string $whole
     */
    private static function compose(Money $money, string $separator, \Closure $whole): string
    {
        $digits = $money->currency()->minorUnits();
        $factor = 10 ** $digits;
        $minor = $money->minor();
        // abs() of PHP_INT_MIN overflows: split before taking the sign off.
        $units = intdiv($minor, $factor);
        $fraction = $minor % $factor;
        $sign = $minor < 0 ? '-' : '';
        $text = $sign.$whole(abs($units));

        return 0 === $digits ? $text : $text.$separator.str_pad((string) abs($fraction), $digits, '0', \STR_PAD_LEFT);
    }
}
