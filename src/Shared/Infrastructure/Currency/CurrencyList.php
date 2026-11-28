<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Shared\Infrastructure\Currency;

use Foresi\Shared\Domain\Currencies;
use Foresi\Shared\Domain\Currency;

/**
 * The ISO 4217 currencies as the REST API and MCP give them (ADR 0049): what a
 * client needs to read and write amounts in minor units without an exponent
 * table of its own.
 */
final class CurrencyList
{
    private function __construct()
    {
    }

    /**
     * @return list<array{code: string, name: string, minor_units: int, numeric_code: int|null, historic: bool}>
     */
    public static function rows(bool $withHistoric): array
    {
        return array_map(
            static fn (Currency $currency): array => [
                'code' => $currency->code(),
                'name' => $currency->name(),
                'minor_units' => $currency->minorUnits(),
                'numeric_code' => $currency->numericCode(),
                'historic' => $currency->isHistoric(),
            ],
            $withHistoric ? Currencies::all() : Currencies::current(),
        );
    }
}
