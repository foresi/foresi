<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Tests\Audit\Infrastructure;

use App\Audit\Infrastructure\Doctrine\AuditValueNormalizer;
use App\Party\Domain\PartyKind;
use App\Party\Domain\VatId;
use App\Tenancy\Domain\TenantId;
use Doctrine\DBAL\Platforms\SQLitePlatform;
use Doctrine\DBAL\Types\Type;
use PHPUnit\Framework\TestCase;

final class AuditValueNormalizerTest extends TestCase
{
    private AuditValueNormalizer $normalizer;

    protected function setUp(): void
    {
        $this->normalizer = new AuditValueNormalizer(new SQLitePlatform());
        foreach (['tenant_id' => \App\Tenancy\Infrastructure\Doctrine\TenantIdType::class, 'vat_id' => \App\Party\Infrastructure\Doctrine\VatIdType::class] as $name => $class) {
            if (!Type::hasType($name)) {
                Type::addType($name, $class);
            }
        }
    }

    public function testScalarsPassThrough(): void
    {
        self::assertNull($this->normalizer->normalize(null, 'string'));
        self::assertTrue($this->normalizer->normalize(true, 'boolean'));
        self::assertSame(5, $this->normalizer->normalize(5, 'integer'));
        self::assertSame(1.5, $this->normalizer->normalize(1.5, 'float'));
        self::assertSame('x', $this->normalizer->normalize('x', 'string'));
    }

    public function testEnumsDatesAndValueObjectsBecomeScalars(): void
    {
        self::assertSame('sole_trader', $this->normalizer->normalize(PartyKind::SoleTrader, 'string'));
        self::assertSame('2026-10-29T10:00:00.000000+02:00', $this->normalizer->normalize(new \DateTimeImmutable('2026-10-29T10:00:00+02:00'), 'datetime_immutable'));
        self::assertSame('CZ12345695', $this->normalizer->normalize(VatId::fromString('CZ12345695'), 'vat_id'));
        $tenant = TenantId::generate();
        self::assertSame($tenant->toString(), $this->normalizer->normalize($tenant, 'tenant_id'));
    }

    public function testArraysAreNormalisedRecursively(): void
    {
        self::assertSame(['a', 'b' => ['person']], $this->normalizer->normalize(['a', 'b' => [PartyKind::Person]], 'json'));
    }

    public function testUnknownObjectsAreRejected(): void
    {
        $this->expectException(\LogicException::class);
        $this->normalizer->normalize(['x' => new \stdClass()], 'json');
    }

    public function testObjectWithoutScalarFormIsRejected(): void
    {
        $this->expectException(\LogicException::class);
        $this->normalizer->normalize(new \stdClass(), 'string');
    }
}
