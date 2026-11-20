<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\TaxesAndContributions\Domain;

use Foresi\Shared\Domain\Currency;
use Foresi\Shared\Domain\Money;

/**
 * Reads the values of the profile back from the JSON it is stored as, where
 * an amount is its minor units in CZK and a day is written as 2026-11-12.
 *
 * @internal
 */
final class Stored
{
    /**
     * @param array<mixed> $data
     */
    public static function string(array $data, string $key): string
    {
        $value = $data[$key] ?? null;

        return \is_string($value) ? $value : throw new \UnexpectedValueException(\sprintf('The stored profile has no text %s.', $key));
    }

    /**
     * @param array<mixed> $data
     */
    public static function int(array $data, string $key): int
    {
        $value = $data[$key] ?? null;

        return \is_int($value) ? $value : throw new \UnexpectedValueException(\sprintf('The stored profile has no number %s.', $key));
    }

    /**
     * @param array<mixed> $data
     */
    public static function bool(array $data, string $key): bool
    {
        return true === ($data[$key] ?? null);
    }

    /**
     * @param array<mixed> $data
     */
    public static function money(array $data, string $key): ?Money
    {
        $value = $data[$key] ?? null;

        return \is_int($value) ? Money::ofMinor($value, Currency::CZK) : null;
    }

    /**
     * @param array<mixed> $data
     */
    public static function day(array $data, string $key): ?\DateTimeImmutable
    {
        $value = $data[$key] ?? null;
        if (!\is_string($value)) {
            return null;
        }

        $day = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);

        return false !== $day ? $day : throw new \UnexpectedValueException(\sprintf('The stored profile has no day %s.', $key));
    }

    /**
     * @param array<mixed> $data
     *
     * @return list<string>
     */
    public static function strings(array $data, string $key): array
    {
        $value = $data[$key] ?? [];

        return \is_array($value) ? array_values(array_filter($value, \is_string(...))) : [];
    }

    /**
     * @param array<mixed> $data
     *
     * @return list<array<mixed>>
     */
    public static function list(array $data, string $key): array
    {
        $value = $data[$key] ?? [];

        return \is_array($value) ? array_values(array_filter($value, \is_array(...))) : [];
    }
}
