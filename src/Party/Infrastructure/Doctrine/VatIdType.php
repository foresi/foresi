<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Party\Infrastructure\Doctrine;

use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Types\StringType;
use Foresi\Party\Domain\VatId;

final class VatIdType extends StringType
{
    public const string NAME = 'vat_id';

    public function convertToPHPValue(mixed $value, AbstractPlatform $platform): ?VatId
    {
        if (null === $value || '' === $value) {
            return null;
        }
        if ($value instanceof VatId) {
            return $value;
        }
        if (!is_string($value)) {
            throw new \InvalidArgumentException('Unexpected VAT id database value.');
        }

        return VatId::fromString($value);
    }

    public function convertToDatabaseValue(mixed $value, AbstractPlatform $platform): ?string
    {
        if (null === $value) {
            return null;
        }
        if (!$value instanceof VatId) {
            throw new \InvalidArgumentException('Expected a VatId.');
        }

        return $value->toString();
    }
}
