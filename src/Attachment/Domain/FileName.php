<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Attachment\Domain;

/**
 * The name a file is shown and downloaded under: the last part of what the
 * browser sent, without control or direction-changing characters that could
 * disguise it, trimmed and at most 255 characters with its extension kept.
 */
final readonly class FileName
{
    public const int MAX_LENGTH = 255;
    public const string FALLBACK = 'file';
    private const int MAX_EXTENSION_LENGTH = 16;

    public static function clean(string $name): string
    {
        $name = mb_scrub($name, 'UTF-8');
        // Both separators: a name from Windows arrives with backslashes.
        $name = (string) preg_replace('~^.*[/\\\\]~su', '', $name);
        // Control and format characters (such as the right-to-left override).
        $name = (string) preg_replace('/[\p{Cc}\p{Cf}\p{Zl}\p{Zp}]+/u', '', $name);
        $normalized = \Normalizer::normalize($name, \Normalizer::FORM_C);
        $name = \is_string($normalized) ? $normalized : $name;
        $name = trim((string) preg_replace('/\s+/u', ' ', $name), " .\u{00A0}");
        if ('' === $name) {
            return self::FALLBACK;
        }
        if (mb_strlen($name) <= self::MAX_LENGTH) {
            return $name;
        }
        $dot = mb_strrpos($name, '.');
        $extension = false === $dot ? '' : mb_substr($name, $dot);
        if (mb_strlen($extension) > self::MAX_EXTENSION_LENGTH) {
            $extension = '';
        }

        return rtrim(mb_substr($name, 0, self::MAX_LENGTH - mb_strlen($extension)), ' .').$extension;
    }
}
