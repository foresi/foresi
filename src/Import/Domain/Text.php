<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Import\Domain;

/** Small text clean-ups shared by the value readers. */
final class Text
{
    /** Trims all kinds of white space, including non-breaking spaces. */
    public static function clean(string $text): string
    {
        return preg_replace('/^\s+|\s+$/u', '', $text) ?? $text;
    }

    /** Removes every white space character, so "1 234" reads as "1234". */
    public static function squash(string $text): string
    {
        return preg_replace('/\s+/u', '', $text) ?? $text;
    }
}
