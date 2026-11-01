<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Invoicing\Domain;

/**
 * The numeric payment reference a document number gives: its digits, letters
 * dropped. A number with more digits than the reference holds gives none.
 */
final class VariableSymbol
{
    private const int MAX_DIGITS = 10;

    public static function fromNumber(string $number): string
    {
        $digits = preg_replace('/\D/', '', $number) ?? '';

        return \strlen($digits) > self::MAX_DIGITS ? '' : $digits;
    }
}
