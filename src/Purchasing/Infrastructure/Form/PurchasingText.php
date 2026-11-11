<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Purchasing\Infrastructure\Form;

use App\Shared\Domain\Currency;
use App\Shared\Domain\Money;
use App\Shared\Infrastructure\Http\TypedNumber;
use Symfony\Component\Translation\LocaleSwitcher;

/**
 * Amounts, rates and dates of received documents in the language of the user,
 * from integer minor units and decimal texts, never a float; and what a person
 * typed read back the way the number input reads it.
 */
final readonly class PurchasingText
{
    private const string AMOUNT = '/^-?\d{1,13}(?:\.\d{1,2})?$/D';

    public function __construct(private LocaleSwitcher $locales)
    {
    }

    /**
     * On screen: "1 210,50 Kč", "1 210,50 EUR".
     */
    public function amount(Money $money): string
    {
        $minor = abs($money->minor());
        $formatter = new \NumberFormatter($this->locales->getLocale(), \NumberFormatter::DECIMAL);
        $whole = (string) $formatter->format(intdiv($minor, 100), \NumberFormatter::TYPE_INT64);

        return \sprintf("%s%s%s%02d\u{a0}%s", $money->isNegative() ? '-' : '', $whole, $this->separator(), $minor % 100, Currency::CZK === $money->currency() ? 'Kč' : $money->currency()->value);
    }

    /**
     * For a field, without grouping: "1210,50" in Czech.
     */
    public function field(?Money $money): string
    {
        if (!$money instanceof Money) {
            return '';
        }

        return \sprintf('%s%d%s%02d', $money->isNegative() ? '-' : '', intdiv(abs($money->minor()), 100), $this->separator(), abs($money->minor()) % 100);
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

        return 1 === preg_match(self::AMOUNT, $decimal) ? Money::of($decimal, $currency) : false;
    }

    /**
     * A rate or a percentage as typed, with a decimal point; the text as typed when it is none.
     */
    public function typedDecimal(string $typed): string
    {
        return TypedNumber::decimal(trim($typed), $this->locales->getLocale());
    }

    private function separator(): string
    {
        return new \NumberFormatter($this->locales->getLocale(), \NumberFormatter::DECIMAL)->getSymbol(\NumberFormatter::DECIMAL_SEPARATOR_SYMBOL);
    }
}
