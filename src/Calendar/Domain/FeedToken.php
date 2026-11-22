<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Calendar\Domain;

/**
 * The secret in a feed address: 32 random bytes (256 bits) in unpadded base64url,
 * 43 characters. Only its SHA-256 is kept, so a copy of the database opens no feed.
 */
final class FeedToken
{
    public const string PATTERN = '[A-Za-z0-9_-]{43}';

    private function __construct()
    {
    }

    public static function generate(): string
    {
        return rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
    }

    public static function hash(#[\SensitiveParameter] string $token): string
    {
        return hash('sha256', $token);
    }
}
