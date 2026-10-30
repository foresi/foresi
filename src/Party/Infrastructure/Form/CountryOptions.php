<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Party\Infrastructure\Form;

/**
 * The countries the address book offers, named in the language of the user
 * (the display names come from the ICU data of the PHP intl extension).
 */
final class CountryOptions
{
    /** The EU member states, then the neighbours the tenants most often trade with. */
    public const array CODES = [
        'CZ', 'SK', 'PL', 'DE', 'AT', 'HU', 'BE', 'BG', 'HR', 'CY', 'DK', 'EE', 'FI', 'FR', 'GR', 'IE', 'IT', 'LV', 'LT', 'LU', 'MT', 'NL', 'PT', 'RO', 'SI', 'ES', 'SE',
        'GB', 'CH', 'NO', 'UA', 'US',
    ];

    public static function supports(string $code): bool
    {
        return \in_array($code, self::CODES, true);
    }

    /**
     * @return array<string, string> code => name; the first two stay on top, the rest is sorted by name
     */
    public static function options(string $locale): array
    {
        $names = [];
        foreach (self::CODES as $code) {
            $name = \Locale::getDisplayRegion('und_'.$code, $locale);
            $names[$code] = false === $name ? $code : $name;
        }
        $top = \array_slice($names, 0, 2, true);
        $rest = \array_slice($names, 2, null, true);
        asort($rest, \SORT_LOCALE_STRING);

        return $top + $rest;
    }
}
