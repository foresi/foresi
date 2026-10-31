<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Shared\Domain;

/**
 * The countries the application works with, by ISO 3166-1 alpha-2 code, and
 * their names in Czech and English (the display names come from the ICU data of
 * the PHP intl extension).
 */
final class Countries
{
    /** The EU member states, then the neighbours the tenants most often trade with. */
    public const array CODES = [
        'CZ', 'SK', 'PL', 'DE', 'AT', 'HU', 'BE', 'BG', 'HR', 'CY', 'DK', 'EE', 'FI', 'FR', 'GR', 'IE', 'IT', 'LV', 'LT', 'LU', 'MT', 'NL', 'PT', 'RO', 'SI', 'ES', 'SE',
        'GB', 'CH', 'NO', 'UA', 'US',
    ];

    /** The locales whose country names are recognised when text is read. */
    private const array NAME_LOCALES = ['en', 'cs'];

    /** Official and everyday names that the ICU data does not carry as the display name. */
    private const array OTHER_NAMES = [
        'CZ' => ['Česká republika', 'Czech Republic'],
        'SK' => ['Slovenská republika'],
        'GB' => ['Great Britain', 'Velká Británie'],
        'US' => ['USA', 'United States of America', 'Spojené státy americké', 'Spojené státy'],
    ];

    public static function supports(string $code): bool
    {
        return \in_array($code, self::CODES, true);
    }

    public static function name(string $code, string $locale): string
    {
        $name = \Locale::getDisplayRegion('und_'.$code, $locale);

        return false === $name ? $code : $name;
    }

    /**
     * Finds a country by its code or by its Czech or English name, ignoring case
     * and diacritics.
     */
    public static function find(string $text): ?string
    {
        $wanted = self::fold($text);
        foreach (self::CODES as $code) {
            if (self::fold($code) === $wanted) {
                return $code;
            }
            foreach (self::NAME_LOCALES as $locale) {
                if (self::fold(self::name($code, $locale)) === $wanted) {
                    return $code;
                }
            }
            foreach (self::OTHER_NAMES[$code] ?? [] as $name) {
                if (self::fold($name) === $wanted) {
                    return $code;
                }
            }
        }

        return null;
    }

    private static function fold(string $text): string
    {
        $folded = transliterator_transliterate('Any-Latin; Latin-ASCII; Lower()', trim($text));

        return false === $folded ? mb_strtolower(trim($text)) : $folded;
    }
}
