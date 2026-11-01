<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Tests\Identity\Domain;

use App\Identity\Domain\Membership;
use App\Identity\Domain\Role;
use App\Identity\Domain\User;
use App\Shared\Domain\Id;
use PHPUnit\Framework\TestCase;

final class MembershipTest extends TestCase
{
    public function testExposesUserRoleAndParty(): void
    {
        $user = new User('testing@example.com', 'hash', 'Example Company');
        $membership = new Membership($user, Role::Client);

        self::assertSame($user, $membership->user());
        self::assertSame(Role::Client, $membership->role());
        self::assertNull($membership->party());
        self::assertTrue(Id::isValid($membership->id()));
    }

    public function testTenantIsStampedOnPersistOnly(): void
    {
        $this->expectException(\LogicException::class);

        new Membership(new User('testing@example.com', 'hash', 'Example Company'), Role::Owner)->tenantId();
    }
}
