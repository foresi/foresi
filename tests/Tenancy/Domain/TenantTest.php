<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Tests\Tenancy\Domain;

use App\Tenancy\Domain\Tenant;
use App\Tenancy\Domain\TenantId;
use PHPUnit\Framework\TestCase;

final class TenantTest extends TestCase
{
    public function testExposesFields(): void
    {
        $id = TenantId::generate();
        $at = new \DateTimeImmutable('2026-01-02 03:04:05');

        $tenant = new Tenant($id, '  Example Company ', $at);

        self::assertTrue($tenant->id()->equals($id));
        self::assertSame('Example Company', $tenant->name());
        self::assertSame($at, $tenant->createdAt());
    }

    public function testRejectsBlankName(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new Tenant(TenantId::generate(), "  \t ", new \DateTimeImmutable());
    }
}
