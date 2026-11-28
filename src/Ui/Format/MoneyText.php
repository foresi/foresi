<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Ui\Format;

use Foresi\Shared\Domain\Currency;
use Foresi\Shared\Domain\Money;
use Foresi\Shared\Domain\MoneyFormat;
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
     * On screen: "1 210,50 Kč" in Czech, "1,210.50 CZK" in English, with as many decimals as the currency has.
     */
    public function shown(Money $money): string
    {
        return MoneyFormat::shown($money, $this->locales->getLocale());
    }

    /**
     * What follows an amount on screen: the symbol ICU gives in the user's language, "Kč" for crowns in Czech.
     */
    public function unit(Currency $currency): string
    {
        return MoneyFormat::unit($currency, $this->locales->getLocale());
    }

    /**
     * Grouped, without the unit: "1 210,50".
     */
    public function grouped(Money $money): string
    {
        return MoneyFormat::grouped($money, $this->locales->getLocale());
    }
}
