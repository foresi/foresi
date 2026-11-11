<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Identity\Domain;

/**
 * The secret of a personal API token: a fixed prefix, so leaked tokens are easy
 * to find, and 32 random bytes in unpadded base64url. Only its SHA-256 is kept;
 * a secret that random needs no slow hash, and a hash can be looked up directly.
 */
final class ApiTokenSecret
{
    public const string PREFIX = 'foresi_pat_';

    /** The prefix and the length of 32 bytes in unpadded base64url. */
    public const string PATTERN = 'foresi_pat_[A-Za-z0-9_-]{43}';

    public static function generate(): string
    {
        return self::PREFIX.rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
    }

    public static function hash(#[\SensitiveParameter] string $secret): string
    {
        return hash('sha256', $secret);
    }

    public static function isWellFormed(#[\SensitiveParameter] string $secret): bool
    {
        return 1 === preg_match('/^'.self::PATTERN.'$/', $secret);
    }

    /**
     * The last four characters, shown to tell tokens apart.
     */
    public static function lastFour(#[\SensitiveParameter] string $secret): string
    {
        return substr($secret, -4);
    }
}
