<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Inbox\Application;

/**
 * Whether a suggestion's quote is an exact span of its item's text, the plain
 * text body foresi_get_inbox_item returns. Byte for byte, with only the line
 * ends made alike (\r\n as \n) on both sides, so a quote copied from either
 * form is found. Where it starts is its first occurrence, in characters of the
 * text so made alike, for a screen to highlight it by offset and length.
 */
final class QuoteCheck
{
    private function __construct()
    {
    }

    /**
     * @param string|null $text the item's text; null when the mail has none or is removed
     *
     * @return int|null the offset of the first occurrence in characters; null when it is not in the text
     */
    public static function offset(?string $text, string $quote): ?int
    {
        if (null === $text || '' === $quote) {
            return null;
        }
        $text = self::lines($text);
        $position = strpos($text, self::lines($quote));

        return false === $position ? null : mb_strlen(substr($text, 0, $position));
    }

    private static function lines(string $text): string
    {
        return str_replace("\r\n", "\n", $text);
    }
}
