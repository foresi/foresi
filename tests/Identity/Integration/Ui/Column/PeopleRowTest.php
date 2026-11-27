<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\Identity\Integration\Ui\Column;

use Foresi\Identity\Application\Person;
use Foresi\Identity\Domain\Invitation;
use Foresi\Identity\Domain\Role;
use Foresi\Identity\Integration\Ui\Column\PeopleRow;
use Foresi\Party\Domain\Party;
use Foresi\Party\Domain\PartyKind;
use PHPUnit\Framework\TestCase;

/**
 * A row of the list of people reads the person or the invitation it holds.
 */
final class PeopleRowTest extends TestCase
{
    public function testAPersonRowReadsThePerson(): void
    {
        $row = PeopleRow::person(new Person('m1', 'u1', 'Alex Example', 'alex@example.com', Role::Guest, 'c1', 'Example Client', false, true));

        self::assertFalse($row->isInvitation());
        self::assertSame('m1', $row->id());
        self::assertSame('Alex Example', $row->name());
        self::assertSame('alex@example.com', $row->email());
        self::assertSame(Role::Guest, $row->role());
        self::assertSame(['id' => 'c1', 'name' => 'Example Client'], $row->contact());
    }

    public function testAPersonWithoutAContactOrItsNameHasNoneOrAnEmptyName(): void
    {
        self::assertNull(PeopleRow::person(new Person('m1', 'u1', 'Alex Example', 'alex@example.com', Role::Member, null, null, false, true))->contact());
        self::assertSame(['id' => 'c1', 'name' => ''], PeopleRow::person(new Person('m1', 'u1', 'Alex Example', 'alex@example.com', Role::Member, 'c1', null, false, true))->contact());
    }

    public function testAnInvitationRowReadsTheInvitation(): void
    {
        $party = new Party(PartyKind::Person, 'Sam Contact', null, null, '');
        $invitation = new Invitation('sam@example.com', 'Sam Example', Role::Guest, $party, 'u1', 'en', str_repeat('a', 64), new \DateTimeImmutable('2026-01-02'));
        $row = PeopleRow::invitation($invitation);

        self::assertTrue($row->isInvitation());
        self::assertSame($invitation->id(), $row->id());
        self::assertSame('Sam Example', $row->name());
        self::assertSame('sam@example.com', $row->email());
        self::assertSame(Role::Guest, $row->role());
        self::assertSame(['id' => $party->id(), 'name' => 'Sam Contact'], $row->contact());
        self::assertNull(PeopleRow::invitation(new Invitation('sam@example.com', 'Sam Example', Role::Member, null, 'u1', 'en', str_repeat('a', 64), new \DateTimeImmutable('2026-01-02')))->contact());
    }
}
