<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Shared\Domain;

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

    /**
     * The values that are UUIDs of any version, in their order. A value that is not
     * a UUID names no row, and PostgreSQL refuses it in a uuid column, so a lookup
     * by ids passes them through this first.
     *
     * @param list<string> $values
     *
     * @return list<string>
     */
    public static function onlyUuids(array $values): array
    {
        return array_values(array_filter($values, Uuid::isValid(...)));
    }
}
