<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Shared\Infrastructure\Doctrine;

use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Types\StringType;
use Foresi\Shared\Domain\Currency;

/**
 * Maps an ISO 4217 code column (char(3) or varchar(3)) to Currency (ADR 0049).
 * The column keeps the declaration its mapping gives, so the type changes no schema.
 */
final class CurrencyType extends StringType
{
    public const string NAME = 'currency';

    public function convertToPHPValue(mixed $value, AbstractPlatform $platform): ?Currency
    {
        if (null === $value || '' === $value) {
            return null;
        }
        if ($value instanceof Currency) {
            return $value;
        }
        if (!\is_string($value)) {
            throw new \InvalidArgumentException('Unexpected currency database value.');
        }

        // A char(3) column may pad; a stored historic code (HRK) still loads.
        return Currency::of(trim($value));
    }

    public function convertToDatabaseValue(mixed $value, AbstractPlatform $platform): ?string
    {
        if (null === $value) {
            return null;
        }
        if ($value instanceof Currency) {
            return $value->code();
        }
        if (\is_string($value)) {
            return Currency::of($value)->code();
        }

        throw new \InvalidArgumentException('Expected a Currency.');
    }
}
