<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Shared\Domain;

use Symfony\Component\Uid\Uuid;
use Symfony\Component\Uid\UuidV7;

/**
 * Identifier helper: every entity id is a lowercase RFC 4122 UUIDv7 string.
 */
final readonly class Id
{
    /**
     * Time-ordered; monotonic within one process.
     */
    public static function generate(): string
    {
        return UuidV7::generate();
    }

    public static function isValid(string $value): bool
    {
        return $value === strtolower($value)
            && Uuid::isValid($value)
            && Uuid::fromString($value) instanceof UuidV7;
    }
}
