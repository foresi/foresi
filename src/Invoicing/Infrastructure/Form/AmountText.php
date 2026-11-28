<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Invoicing\Infrastructure\Form;

use Foresi\Shared\Domain\Currency;
use Foresi\Shared\Domain\Money;
use Foresi\Shared\Domain\MoneyFormat;
use Foresi\Ui\Format\MoneyText;
use Symfony\Component\Translation\LocaleSwitcher;

/**
 * Amounts as text in the language of the user, from integer minor units: no float
 * ever holds the amount. On screen they read as every list shows money (MoneyText).
 */
final readonly class AmountText
{
    private MoneyText $money;

    public function __construct(private LocaleSwitcher $locales)
    {
        $this->money = new MoneyText($locales);
    }

    /**
     * On screen: "1 210,50 Kč", "1 210,50 EUR".
     */
    public function shown(Money $money): string
    {
        return $this->money->shown($money);
    }

    /**
     * What follows an amount on screen: "Kč" for crowns, the code for the rest.
     */
    public function unit(Currency $currency): string
    {
        return $this->money->unit($currency);
    }

    /**
     * For a field, without grouping: "1210,50".
     */
    public function plain(Money $money): string
    {
        return $this->localised(MoneyFormat::decimal($money));
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
        return $this->money->grouped($money);
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
