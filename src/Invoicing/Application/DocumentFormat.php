<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Invoicing\Application;

use App\Party\Domain\DocumentLanguage;
use App\Shared\Domain\Currency;
use App\Shared\Domain\Money;

/**
 * Amounts and dates as a document prints them, in its own language. The PDF and
 * the mail both read them from here, so the two can never differ.
 */
final readonly class DocumentFormat
{
    /**
     * "1 210,50 Kč" in Czech, "1,210.50 CZK" in English, from integer minor units:
     * no float ever holds the amount. Crowns are "Kč" on a Czech document, as a
     * Czech reader writes them; every other currency, and crowns in English, keep
     * the ISO code. Machine formats such as ISDOC never come through here.
     */
    public function money(Money $money, DocumentLanguage $language): string
    {
        $minor = abs($money->minor());
        $formatter = new \NumberFormatter($language->value, \NumberFormatter::DECIMAL);
        $whole = (string) $formatter->format(intdiv($minor, 100), \NumberFormatter::TYPE_INT64);

        return \sprintf("%s%s%s%02d\u{a0}%s", $money->isNegative() ? '-' : '', $whole, $formatter->getSymbol(\NumberFormatter::DECIMAL_SEPARATOR_SYMBOL), $minor % 100, $this->currency($money->currency(), $language));
    }

    private function currency(Currency $currency, DocumentLanguage $language): string
    {
        return Currency::CZK === $currency && DocumentLanguage::Czech === $language ? 'Kč' : $currency->value;
    }

    /**
     * "1. 11. 2026" in Czech, "1 Nov 2026" in English.
     */
    public function date(\DateTimeImmutable $date, DocumentLanguage $language): string
    {
        return $date->format(match ($language) {
            DocumentLanguage::Czech => 'j. n. Y',
            DocumentLanguage::English => 'j M Y',
        });
    }
}
