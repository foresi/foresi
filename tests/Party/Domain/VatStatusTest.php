<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Tests\Party\Domain;

use App\Party\Domain\VatStatus;
use PHPUnit\Framework\TestCase;

final class VatStatusTest extends TestCase
{
    public function testOnlyAnUnregisteredFirmWorksWithoutAVatId(): void
    {
        self::assertTrue(VatStatus::Registered->requiresVatId());
        self::assertTrue(VatStatus::IdentifiedPerson->requiresVatId());
        self::assertFalse(VatStatus::NotRegistered->requiresVatId());
    }

    public function testOnlyARegisteredPayerChargesVat(): void
    {
        self::assertTrue(VatStatus::Registered->chargesVat());
        self::assertFalse(VatStatus::IdentifiedPerson->chargesVat());
        self::assertFalse(VatStatus::NotRegistered->chargesVat());
    }
}
