<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\ReceivedDocuments\Infrastructure\Form;

use Foresi\Shared\Domain\Currency;
use Foresi\Shared\Domain\Money;
use Foresi\Shared\Domain\MoneyFormat;
use Foresi\Shared\Infrastructure\Http\TypedNumber;
use Symfony\Component\Translation\LocaleSwitcher;

/**
 * Amounts, rates and dates of received documents in the language of the user,
 * from integer minor units and decimal texts, never a float; and what a person
 * typed read back the way the number input reads it.
 */
final readonly class PurchasingText
{
    public function __construct(private LocaleSwitcher $locales)
    {
    }

    /**
     * On screen: "1 210,50 Kč", "1 210,50 EUR".
     */
    public function amount(Money $money): string
    {
        return \sprintf("%s\u{a0}%s", MoneyFormat::grouped($money, $this->locales->getLocale()), Currency::of('CZK') === $money->currency() ? 'Kč' : $money->currency()->code());
    }

    /**
     * For a field, without grouping: "1210,50" in Czech.
     */
    public function field(?Money $money): string
    {
        if (!$money instanceof Money) {
            return '';
        }

        return MoneyFormat::field($money, $this->locales->getLocale());
    }

    /**
     * A decimal as the language writes it: "24.315" gives "24,315" in Czech.
     */
    public function decimal(string $decimal): string
    {
        return str_replace('.', $this->separator(), $decimal);
    }

    /**
     * An amount as typed, negative for a credit note too.
     *
     * @return Money|false|null null for a blank field, false for text that is no amount
     */
    public function typedAmount(string $typed, Currency $currency): Money|false|null
    {
        if ('' === trim($typed)) {
            return null;
        }
        $decimal = TypedNumber::decimal($typed, $this->locales->getLocale());

        return 1 === preg_match($this->amountPattern($currency), $decimal) ? Money::of($decimal, $currency) : false;
    }

    /**
     * A rate or a percentage as typed, with a decimal point; the text as typed when it is none.
     */
    public function typedDecimal(string $typed): string
    {
        return TypedNumber::decimal(trim($typed), $this->locales->getLocale());
    }

    private function amountPattern(Currency $currency): string
    {
        $digits = $currency->minorUnits();

        return 0 === $digits ? '/^-?\d{1,13}$/D' : \sprintf('/^-?\d{1,13}(?:\.\d{1,%d})?$/D', $digits);
    }

    private function separator(): string
    {
        return new \NumberFormatter($this->locales->getLocale(), \NumberFormatter::DECIMAL)->getSymbol(\NumberFormatter::DECIMAL_SEPARATOR_SYMBOL);
    }
}
