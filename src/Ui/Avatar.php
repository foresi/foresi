<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Ui;

/**
 * The small initials avatar of a person: up to two letters of the name on a
 * colour of the brand that a stable seed, such as the user id, always picks the
 * same. The name is always written next to it, so the colour carries nothing.
 */
final class Avatar
{
    /** Pairs of the theme that pass the text contrast test. */
    public const array TONES = [
        'bg-badge-green text-badge-green-text',
        'bg-badge-amber text-badge-amber-text',
        'bg-badge-gray text-badge-gray-text',
        'bg-green text-white',
        'bg-ink text-white',
    ];

    public static function initials(string $name): string
    {
        $words = preg_split('/\s+/u', $name, -1, \PREG_SPLIT_NO_EMPTY);
        $words = false === $words ? [] : $words;
        $letters = array_map(static fn (string $word): string => mb_strtoupper(mb_substr($word, 0, 1)), \array_slice($words, 0, 2));

        return implode('', $letters);
    }

    public static function tone(string $seed): string
    {
        return self::TONES[crc32($seed) % \count(self::TONES)];
    }
}
