<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Tests\Tenancy\Domain;

use App\Tenancy\Domain\TenantId;
use PHPUnit\Framework\TestCase;

final class TenantIdTest extends TestCase
{
    public function testGeneratesUuidV7(): void
    {
        $before = (int) floor(microtime(true) * 1000);
        $id = TenantId::generate();
        $after = (int) floor(microtime(true) * 1000);

        $string = $id->toString();
        self::assertMatchesRegularExpression('/^[0-9a-f]{8}-[0-9a-f]{4}-7[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/', $string);

        $millis = (int) hexdec(str_replace('-', '', substr($string, 0, 13)));
        self::assertGreaterThanOrEqual($before, $millis);
        self::assertLessThanOrEqual($after, $millis);
    }

    public function testLayoutWithAllZeroRandomBytes(): void
    {
        $id = TenantId::fromParts(0x0123456789AB, str_repeat("\x00", 10));

        self::assertSame('01234567-89ab-7000-8000-000000000000', $id->toString());
    }

    public function testLayoutWithAllOneRandomBytes(): void
    {
        $id = TenantId::fromParts(0x0123456789AB, str_repeat("\xFF", 10));

        self::assertSame('01234567-89ab-7fff-bfff-ffffffffffff', $id->toString());
    }

    public function testLayoutKeepsDistinctRandomBytesInPlace(): void
    {
        $id = TenantId::fromParts(0, "\x10\x21\x32\x43\x54\x65\x76\x87\x98\xA9");

        self::assertSame('00000000-0000-7021-b243-5465768798a9', $id->toString());
    }

    public function testFromPartsRejectsWrongRandomLength(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        TenantId::fromParts(0, str_repeat("\x00", 9));
    }

    public function testRejectsTooManyRandomBytes(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        TenantId::fromParts(0, str_repeat("\x00", 11));
    }

    public function testGeneratedIdsAreUnique(): void
    {
        $seen = [];
        for ($i = 0; $i < 200; ++$i) {
            $seen[TenantId::generate()->toString()] = true;
        }

        self::assertCount(200, $seen);
    }

    public function testVersionAndVariantBitsAreAlwaysSet(): void
    {
        for ($i = 0; $i < 200; ++$i) {
            self::assertMatchesRegularExpression('/^.{14}7.{4}[89ab]/', TenantId::generate()->toString());
        }
    }

    public function testRoundTripsFromString(): void
    {
        $id = TenantId::generate();

        self::assertTrue($id->equals(TenantId::fromString(strtoupper($id->toString()))));
        self::assertSame($id->toString(), TenantId::fromString($id->toString())->toString());
    }

    public function testEqualsIsFalseForDifferentIds(): void
    {
        self::assertFalse(TenantId::generate()->equals(TenantId::generate()));
    }

    public function testRejectsNonV7Uuid(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        TenantId::fromString('6ba7b810-9dad-11d1-80b4-00c04fd430c8');
    }

    public function testRejectsGarbage(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        TenantId::fromString('not-a-uuid');
    }

    public function testCanBeUsedAsString(): void
    {
        $id = TenantId::fromParts(0, str_repeat("\x00", 10));

        self::assertSame($id->toString(), (string) $id);
    }
}
