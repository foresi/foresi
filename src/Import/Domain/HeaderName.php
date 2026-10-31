<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Import\Domain;

/** The one way a column header is compared: lower-case ASCII words separated by single spaces. */
final class HeaderName
{
    private static ?\Transliterator $transliterator = null;

    /** "Název Firmy_(1)" becomes "nazev firmy 1"; a byte order mark and other noise vanish. */
    public static function normalise(string $header): string
    {
        $transliterator = self::$transliterator ??= \Transliterator::create('Any-Latin; Latin-ASCII; Lower()')
            ?? throw new \LogicException('The ICU transliterator is not available.');
        $ascii = $transliterator->transliterate($header);
        $words = preg_replace('/[^a-z0-9]+/', ' ', false === $ascii ? $header : $ascii);

        return trim($words ?? '');
    }

    /** The normalised name without spaces, so "E-mail" and "email" or "first name" and "firstname" meet. */
    public static function compact(string $header): string
    {
        return str_replace(' ', '', self::normalise($header));
    }
}
