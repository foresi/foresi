<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Import\Application;

/**
 * The name of an uploaded file as the import keeps and shows it: no path, no
 * control characters, valid UTF-8 and at most 255 characters with the
 * extension kept, because the browser sends whatever the client chooses.
 */
final class ImportFileName
{
    public const int MAX_LENGTH = 255;

    /** Kept whole only when it looks like an extension; a long tail is part of the name. */
    private const int MAX_EXTENSION = 16;

    private const string FALLBACK = 'import';

    public static function normalise(string $name): string
    {
        $name = mb_scrub($name, 'UTF-8');
        // A path in the name (C:\fakepath\..., ../..) keeps only its last part.
        $parts = preg_split('#[/\\\\]#u', $name);
        $name = false === $parts ? $name : (string) end($parts);
        $name = trim((string) preg_replace('/[\p{Cc}\p{Cf}]+/u', '', $name));
        $name = ltrim($name, '.');
        if ('' === $name) {
            return self::FALLBACK;
        }
        if (mb_strlen($name) <= self::MAX_LENGTH) {
            return $name;
        }

        $dot = mb_strrpos($name, '.');
        $extension = false === $dot ? '' : mb_substr($name, $dot);
        if (mb_strlen($extension) > self::MAX_EXTENSION) {
            $extension = '';
        }

        return mb_substr('' === $extension ? $name : mb_substr($name, 0, $dot), 0, self::MAX_LENGTH - mb_strlen($extension)).$extension;
    }
}
