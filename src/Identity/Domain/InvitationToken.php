<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Identity\Domain;

/**
 * The secret of an invitation link: 32 random bytes, base64url in the link,
 * kept only as its SHA-256.
 */
final class InvitationToken
{
    /** The length of 32 bytes in unpadded base64url. */
    public const string PATTERN = '[A-Za-z0-9_-]{43}';

    public static function generate(): string
    {
        return rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
    }

    public static function hash(string $token): string
    {
        return hash('sha256', $token);
    }

    public static function isWellFormed(string $token): bool
    {
        return 1 === preg_match('/^'.self::PATTERN.'$/', $token);
    }
}
