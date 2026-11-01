<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Invoicing\Domain;

/**
 * The form two document numbers are compared in: upper case Latin letters and
 * digits only, so punctuation, case and diacritics never make two numbers different.
 * It also names the file of a document, unique per firm.
 */
final class NumberKey
{
    public static function of(string $number): string
    {
        $latin = transliterator_transliterate('Any-Latin; Latin-ASCII; Upper', $number);

        return preg_replace('/[^A-Z0-9]/', '', false === $latin ? strtoupper($number) : $latin) ?? '';
    }
}
