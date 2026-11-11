<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Identity\Domain;

/**
 * Where a personal API token stands. A revoked token stays revoked once it has
 * expired as well, so the owner sees what was done to it.
 */
enum ApiTokenState: string
{
    case Active = 'active';
    case Expired = 'expired';
    case Revoked = 'revoked';

    /**
     * A token expires at the very moment of its expiry time.
     */
    public static function of(?\DateTimeImmutable $revokedAt, \DateTimeImmutable $expiresAt, \DateTimeImmutable $now): self
    {
        return match (true) {
            $revokedAt instanceof \DateTimeImmutable => self::Revoked,
            $now >= $expiresAt => self::Expired,
            default => self::Active,
        };
    }
}
