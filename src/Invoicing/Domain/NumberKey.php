<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Invoicing\Domain;

/**
 * The form two document numbers are compared in: upper case, letters and digits
 * only, so punctuation and case never make two numbers different.
 */
final class NumberKey
{
    public static function of(string $number): string
    {
        return preg_replace('/[^A-Z0-9]/', '', strtoupper($number)) ?? '';
    }
}
