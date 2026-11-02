<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Shared\Infrastructure\Http;

/**
 * A number as people type or paste it: "1 000,50", "1000.5", "1,000.50". The server's
 * half of the number-input Stimulus controller, which reads the same forms, so a form
 * means the same with and without JavaScript. Separators count only where they group
 * thousands properly; anything else comes back as typed, for the caller to refuse.
 */
final class TypedNumber
{
    private const array SPACES = ["\u{a0}", "\u{202f}", "\u{2009}"];

    /**
     * @return string the number with a decimal point, or the text as typed
     */
    public static function decimal(string $typed, string $locale): string
    {
        $text = trim(str_replace(self::SPACES, ' ', $typed));
        if ('' === $text) {
            return '';
        }
        if (str_contains($text, ' ')) {
            return 1 === preg_match('/^-?\d{1,3}(?: \d{3})+(?:[.,]\d*)?$/D', $text) ? str_replace([' ', ','], ['', '.'], $text) : $typed;
        }

        $comma = strrpos($text, ',');
        $dot = strrpos($text, '.');
        if (false !== $comma && false !== $dot) {
            return self::mixed($text, $comma > $dot ? ',' : '.', $comma > $dot ? '.' : ',') ?? $typed;
        }
        foreach ([',', '.'] as $separator) {
            if (substr_count($text, $separator) > 1) {
                return 1 === preg_match('/^-?\d{1,3}(?:'.preg_quote($separator, '/').'\d{3})+$/D', $text) ? str_replace($separator, '', $text) : $typed;
            }
        }
        if (false !== $comma && self::commaGroups($locale) && 1 === preg_match('/^-?\d{1,3},\d{3}$/D', $text)) {
            return str_replace(',', '', $text);
        }

        return str_replace(',', '.', $text);
    }

    private static function mixed(string $text, string $decimal, string $group): ?string
    {
        $pattern = \sprintf('/^-?\d{1,3}(?:%s\d{3})+%s\d*$/D', preg_quote($group, '/'), preg_quote($decimal, '/'));

        return 1 === preg_match($pattern, $text) ? str_replace([$group, $decimal], ['', '.'], $text) : null;
    }

    private static function commaGroups(string $locale): bool
    {
        return ',' === new \NumberFormatter($locale, \NumberFormatter::DECIMAL)->getSymbol(\NumberFormatter::GROUPING_SEPARATOR_SYMBOL);
    }
}
