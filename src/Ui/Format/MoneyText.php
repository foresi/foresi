<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Ui\Format;

use Foresi\Shared\Domain\Currency;
use Foresi\Shared\Domain\Money;
use Symfony\Component\Translation\LocaleSwitcher;

/**
 * An amount as the screens show it, in the language of the user, from integer minor units: no
 * float ever holds the amount.
 */
final readonly class MoneyText
{
    public function __construct(private LocaleSwitcher $locales)
    {
    }

    /**
     * On screen: "1 210,50 Kč", "1 210,50 EUR".
     */
    public function shown(Money $money): string
    {
        return \sprintf("%s\u{a0}%s", $this->grouped($money), $this->unit($money->currency()));
    }

    /**
     * What follows an amount on screen: "Kč" for crowns, the code for the rest.
     */
    public function unit(Currency $currency): string
    {
        return Currency::CZK === $currency ? 'Kč' : $currency->value;
    }

    /**
     * Grouped, without the unit: "1 210,50".
     */
    public function grouped(Money $money): string
    {
        $minor = abs($money->minor());
        $formatter = new \NumberFormatter($this->locales->getLocale(), \NumberFormatter::DECIMAL);

        return \sprintf('%s%s%s%02d', $money->isNegative() ? '-' : '', $formatter->format(intdiv($minor, 100), \NumberFormatter::TYPE_INT64), $formatter->getSymbol(\NumberFormatter::DECIMAL_SEPARATOR_SYMBOL), $minor % 100);
    }
}
