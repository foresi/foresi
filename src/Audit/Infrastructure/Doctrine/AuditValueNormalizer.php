<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Audit\Infrastructure\Doctrine;

use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Types\Type;

/**
 * Turns a mapped field value into a stable JSON scalar (or list/map of them).
 * Value objects go through their Doctrine type (the form stored in the
 * database), enums through their value, dates through ISO 8601 with offset.
 * Anything else is rejected: values are never PHP-serialised.
 */
final readonly class AuditValueNormalizer
{
    public function __construct(private AbstractPlatform $platform)
    {
    }

    /**
     * @return array<mixed>|bool|float|int|string|null
     */
    public function normalize(mixed $value, string $doctrineType): array|bool|float|int|string|null
    {
        if (null === $value || \is_bool($value) || \is_int($value) || \is_float($value) || \is_string($value)) {
            return $value;
        }
        if ($value instanceof \BackedEnum) {
            return $value->value;
        }
        if ($value instanceof \DateTimeInterface) {
            return $value->format('Y-m-d\TH:i:s.uP');
        }
        if (\is_array($value)) {
            return $this->normalizeArray($value);
        }

        $converted = Type::getType($doctrineType)->convertToDatabaseValue($value, $this->platform);
        if (null === $converted || \is_scalar($converted)) {
            return $converted;
        }

        throw new \LogicException(sprintf('Cannot write a %s value to the audit log.', get_debug_type($value)));
    }

    /**
     * @param array<mixed> $values
     *
     * @return array<mixed>
     */
    private function normalizeArray(array $values): array
    {
        $result = [];
        foreach ($values as $key => $item) {
            if (\is_object($item) && !$item instanceof \BackedEnum && !$item instanceof \DateTimeInterface) {
                throw new \LogicException(sprintf('Cannot write a %s value to the audit log.', get_debug_type($item)));
            }
            $result[$key] = $this->normalize($item, 'string');
        }

        return $result;
    }
}
