<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Identity\Domain\OAuth;

/**
 * Proof Key for Code Exchange with the S256 method only (RFC 7636): the
 * challenge is the unpadded base64url SHA-256 of the verifier.
 */
final class Pkce
{
    public static function isVerifier(#[\SensitiveParameter] string $verifier): bool
    {
        return 1 === preg_match('/^[A-Za-z0-9._~-]{43,128}$/', $verifier);
    }

    public static function isChallenge(string $challenge): bool
    {
        return 1 === preg_match('/^[A-Za-z0-9_-]{43}$/', $challenge);
    }

    public static function challenge(#[\SensitiveParameter] string $verifier): string
    {
        return rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');
    }

    public static function verifies(#[\SensitiveParameter] string $verifier, string $challenge): bool
    {
        return self::isVerifier($verifier) && hash_equals($challenge, self::challenge($verifier));
    }
}
