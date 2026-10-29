<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Tenancy\Infrastructure\Doctrine;

use App\Tenancy\Domain\TenantId;
use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Types\GuidType;

/**
 * Stores a TenantId as a UUID: native uuid on PostgreSQL, CHAR(36) on SQLite.
 */
final class TenantIdType extends GuidType
{
    public const string NAME = 'tenant_id';

    public function convertToPHPValue(mixed $value, AbstractPlatform $platform): ?TenantId
    {
        if (null === $value || '' === $value) {
            return null;
        }
        if ($value instanceof TenantId) {
            return $value;
        }
        if (!is_string($value)) {
            throw new \InvalidArgumentException('Unexpected tenant id database value.');
        }

        return TenantId::fromString($value);
    }

    public function convertToDatabaseValue(mixed $value, AbstractPlatform $platform): ?string
    {
        if (null === $value) {
            return null;
        }
        if (!$value instanceof TenantId) {
            throw new \InvalidArgumentException('Expected a TenantId.');
        }

        return $value->toString();
    }
}
