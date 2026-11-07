<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Tests\Identity\Domain;

use App\Identity\Domain\Membership;
use App\Identity\Domain\Role;
use App\Identity\Domain\User;
use App\Party\Domain\Party;
use App\Party\Domain\PartyKind;
use App\Shared\Domain\Id;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class MembershipTest extends TestCase
{
    public function testExposesUserRoleAndParty(): void
    {
        $user = new User('testing@example.com', 'hash', 'Example Company');
        $party = $this->contact();
        $membership = new Membership($user, Role::Guest, $party);

        self::assertSame($user, $membership->user());
        self::assertSame(Role::Guest, $membership->role());
        self::assertSame($party, $membership->party());
        self::assertTrue(Id::isValid($membership->id()));
    }

    public function testAGuestMembershipMustLinkAContact(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageIsOrContains('A guest membership must link the contact the person represents.');

        new Membership(new User('testing@example.com', 'hash', 'Example Company'), Role::Guest);
    }

    #[DataProvider('staff')]
    public function testTheStaffMayLinkTheirOwnRecordOrNone(Role $role): void
    {
        $user = new User('testing@example.com', 'hash', 'Example Company');
        $party = $this->contact();

        self::assertNull(new Membership($user, $role)->party());
        self::assertSame($party, new Membership($user, $role, $party)->party());
    }

    /**
     * @return iterable<string, array{Role}>
     */
    public static function staff(): iterable
    {
        yield 'owner' => [Role::Owner];
        yield 'member' => [Role::Member];
    }

    public function testOnlyTheGuestRoleIsNotStaff(): void
    {
        self::assertSame(['owner' => true, 'member' => true, 'guest' => false], array_combine(
            array_map(static fn (Role $role): string => $role->value, Role::cases()),
            array_map(static fn (Role $role): bool => $role->isStaff(), Role::cases()),
        ));
    }

    public function testTenantIsStampedOnPersistOnly(): void
    {
        $this->expectException(\LogicException::class);

        new Membership(new User('testing@example.com', 'hash', 'Example Company'), Role::Owner)->tenantId();
    }

    private function contact(): Party
    {
        return new Party(PartyKind::Person, 'Alex Example', null, null, '');
    }
}
