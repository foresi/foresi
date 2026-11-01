<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Invoicing\Infrastructure\Form;

use App\Shared\Domain\Money;
use Symfony\Component\Translation\LocaleSwitcher;

/**
 * Amounts as text in the language of the user, from integer minor units: no float
 * ever holds the amount.
 */
final readonly class AmountText
{
    public function __construct(private LocaleSwitcher $locales)
    {
    }

    /**
     * For reading: "1 210,50 CZK".
     */
    public function money(Money $money): string
    {
        return \sprintf("%s\u{a0}%s", $this->grouped($money), $money->currency()->value);
    }

    /**
     * For a field, without grouping: "1210,50".
     */
    public function plain(Money $money): string
    {
        return $this->localised(\sprintf('%s%d.%02d', $money->isNegative() ? '-' : '', intdiv(abs($money->minor()), 100), abs($money->minor()) % 100));
    }

    /**
     * A decimal without the zeros it does not need: "1.5000" gives "1,5".
     */
    public function trimmed(string $decimal): string
    {
        return $this->localised(rtrim(rtrim($decimal, '0'), '.'));
    }

    /**
     * For a column of a document: "1 210,50".
     */
    public function grouped(Money $money): string
    {
        $minor = abs($money->minor());
        $formatter = new \NumberFormatter($this->locales->getLocale(), \NumberFormatter::DECIMAL);
        $whole = (string) $formatter->format(intdiv($minor, 100), \NumberFormatter::TYPE_INT64);

        return \sprintf('%s%s%s%02d', $money->isNegative() ? '-' : '', $whole, $this->symbol(\NumberFormatter::DECIMAL_SEPARATOR_SYMBOL), $minor % 100);
    }

    /**
     * For a quantity on a document: at least two decimals, "1,00" or "1,125".
     */
    public function quantity(string $decimal): string
    {
        $trimmed = rtrim($decimal, '0');
        $decimals = \strlen($trimmed) - (int) strpos($trimmed, '.') - 1;

        return $this->localised($decimals < 2 ? $trimmed.str_repeat('0', 2 - $decimals) : $trimmed);
    }

    private function localised(string $decimal): string
    {
        return str_replace('.', $this->symbol(\NumberFormatter::DECIMAL_SEPARATOR_SYMBOL), $decimal);
    }

    private function symbol(int $symbol): string
    {
        return new \NumberFormatter($this->locales->getLocale(), \NumberFormatter::DECIMAL)->getSymbol($symbol);
    }
}
