<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Shared\Infrastructure\Http;

/**
 * Accepts a redirect target only if it is a path on this site, so a form
 * field or query parameter can never send a user to another origin.
 */
final class LocalPath
{
    public static function resolve(?string $candidate, string $fallback): string
    {
        if (null === $candidate || '' === $candidate || !str_starts_with($candidate, '/')) {
            return $fallback;
        }
        // "//host" is scheme-relative, "/\host" is read as "//host" by browsers, and control characters split headers.
        if (str_starts_with($candidate, '//') || str_contains($candidate, '\\') || 1 === preg_match('/[\x00-\x20\x7F]/', $candidate)) {
            return $fallback;
        }

        return $candidate;
    }
}
