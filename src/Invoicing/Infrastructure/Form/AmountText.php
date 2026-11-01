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

    private function localised(string $decimal): string
    {
        return str_replace('.', $this->symbol(\NumberFormatter::DECIMAL_SEPARATOR_SYMBOL), $decimal);
    }

    private function grouped(Money $money): string
    {
        $minor = abs($money->minor());
        $formatter = new \NumberFormatter($this->locales->getLocale(), \NumberFormatter::DECIMAL);
        $whole = (string) $formatter->format(intdiv($minor, 100), \NumberFormatter::TYPE_INT64);

        return \sprintf('%s%s%s%02d', $money->isNegative() ? '-' : '', $whole, $this->symbol(\NumberFormatter::DECIMAL_SEPARATOR_SYMBOL), $minor % 100);
    }

    private function symbol(int $symbol): string
    {
        return new \NumberFormatter($this->locales->getLocale(), \NumberFormatter::DECIMAL)->getSymbol($symbol);
    }
}
