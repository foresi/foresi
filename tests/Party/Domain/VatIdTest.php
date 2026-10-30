<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Tests\Party\Domain;

use App\Party\Domain\InvalidVatId;
use App\Party\Domain\VatId;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class VatIdTest extends TestCase
{
    public function testForeignEuVatIdsAccepted(): void
    {
        self::assertSame('SK', VatId::fromString('SK2020123456')->country());
        self::assertSame('DE', VatId::fromString('DE123456789')->country());
    }

    public function testCzechVatIdRequires8To10Digits(): void
    {
        self::assertSame('CZ12345695', VatId::fromString('CZ12345695')->toString());
        self::assertSame('CZ1234567890', VatId::fromString('CZ1234567890')->toString());
    }

    #[DataProvider('invalidVatIds')]
    public function testMalformedRejected(string $bad): void
    {
        $this->expectException(InvalidVatId::class);

        VatId::fromString($bad);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function invalidVatIds(): iterable
    {
        foreach (['CZ1234567', 'CZ12345678901', 'CZ1234567A', '', '12345678', 'D1234567', 'DE1', 'DE1234567890123', 'DE-123456', '-DE123456789'] as $bad) {
            yield '' === $bad ? 'empty' : $bad => [$bad];
        }
    }

    public function testInputIsNormalised(): void
    {
        self::assertSame('DE123456789', VatId::fromString(' de 123456789 ')->toString());
    }
}
