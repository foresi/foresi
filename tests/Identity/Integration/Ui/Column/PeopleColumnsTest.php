<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\Identity\Integration\Ui\Column;

use Foresi\Identity\Application\Person;
use Foresi\Identity\Contract\PersonContactPicker;
use Foresi\Identity\Domain\Invitation;
use Foresi\Identity\Domain\Role;
use Foresi\Identity\Integration\Ui\Column\PeopleColumns;
use Foresi\Identity\Integration\Ui\Column\PeopleRow;
use Foresi\Party\Contract\ContactPages;
use Foresi\Party\Domain\Party;
use Foresi\Party\Domain\PartyKind;
use PHPUnit\Framework\TestCase;

/**
 * The columns of the list of people: which a person and an invitation each have, in which
 * order, and what each shows.
 */
final class PeopleColumnsTest extends TestCase
{
    public function testAnotherListHasNoColumns(): void
    {
        self::assertSame([], $this->columns()->attributes('identity.api_tokens'));
    }

    public function testWithoutTheContactPickerThereIsNoContactColumn(): void
    {
        self::assertSame([
            ['name', 'people.column.name', 'text', true, 10, true, true, true, []],
            ['email', 'people.column.email', 'text', true, 20, false, false, true, []],
            ['role', 'people.column.role', 'text', true, 30, false, true, false, []],
            ['two_factor', 'people.column.two_factor', 'text', true, 50, false, false, false, ['twoFactor']],
            ['status', 'people.column.status', 'text', true, 60, false, false, false, []],
            ['expires', 'people.column.expires', 'text', true, 70, false, true, false, []],
        ], ColumnTestSupport::describe($this->columns()->attributes('identity.people')));
    }

    public function testWithTheContactPickerTheContactColumnComesFourth(): void
    {
        $attributes = [...$this->columns(true)->attributes('identity.people')];

        self::assertSame(['name', 'email', 'role', 'contact', 'two_factor', 'status', 'expires'], array_column(ColumnTestSupport::describe($attributes), 0));
        self::assertSame(['contact', 'people.column.contact', 'text', true, 40, false, false, true, []], ColumnTestSupport::describe($attributes)[3]);
    }

    public function testThePeopleColumnsAreOfPeopleAndTheInvitationOnesOfInvitations(): void
    {
        $attributes = [...$this->columns()->attributes('identity.people')];
        $people = ['invitations' => false];
        $invitations = ['invitations' => true];

        foreach (['name', 'email', 'role'] as $key) {
            self::assertTrue(ColumnTestSupport::find($attributes, $key)->available($people));
            self::assertTrue(ColumnTestSupport::find($attributes, $key)->available($invitations));
        }
        foreach (['two_factor', 'status'] as $key) {
            self::assertTrue(ColumnTestSupport::find($attributes, $key)->available([]));
            self::assertTrue(ColumnTestSupport::find($attributes, $key)->available($people));
            self::assertFalse(ColumnTestSupport::find($attributes, $key)->available($invitations));
            self::assertTrue(ColumnTestSupport::find($attributes, $key)->available(['invitations' => 'yes']), 'Only true names the invitations.');
        }
        $expires = ColumnTestSupport::find($attributes, 'expires');
        self::assertFalse($expires->available([]));
        self::assertFalse($expires->available($people));
        self::assertTrue($expires->available($invitations));
        self::assertFalse($expires->available(['invitations' => 'yes']));
    }

    public function testAPersonShowsTheirNameWithPhotoEmailRoleTwoFactorAndStatus(): void
    {
        $attributes = [...$this->columns(true)->attributes('identity.people')];
        $owner = PeopleRow::person(new Person('m1', 'u1', 'Alex Example', 'alex@example.com', Role::Owner, 'c1', 'Example Client', true, true));
        $guest = PeopleRow::person(new Person('m2', 'u2', 'Sam Example', 'sam@example.com', Role::Guest, null, null, false, false));

        $name = ColumnTestSupport::find($attributes, 'name');
        self::assertSame('Alex Example', $name->value($owner));
        self::assertSame(['avatar' => 'u1', 'user' => 'u1', 'text' => 'Alex Example'], $name->cell($owner));
        $email = ColumnTestSupport::find($attributes, 'email');
        self::assertSame('alex@example.com', $email->value($owner));
        self::assertSame('alex@example.com', $email->cell($owner));
        $role = ColumnTestSupport::find($attributes, 'role');
        self::assertSame('role.owner', $role->value($owner));
        self::assertSame(['text' => 'role.owner', 'tone' => 'green'], $role->cell($owner));
        self::assertSame(['text' => 'role.guest', 'tone' => 'gray'], $role->cell($guest));
        $factor = ColumnTestSupport::find($attributes, 'two_factor');
        self::assertSame('people.two_factor.on', $factor->value($owner));
        self::assertSame(['text' => 'people.two_factor.on', 'tone' => 'green'], $factor->cell($owner));
        self::assertSame('people.two_factor.off', $factor->value($guest));
        self::assertSame(['text' => 'people.two_factor.off', 'tone' => 'gray'], $factor->cell($guest));
        $status = ColumnTestSupport::find($attributes, 'status');
        self::assertSame('people.status.active', $status->value($owner));
        self::assertSame(['text' => 'people.status.active', 'tone' => 'green'], $status->cell($owner));
        self::assertSame('people.status.deactivated', $status->value($guest));
        self::assertSame(['text' => 'people.status.deactivated', 'tone' => 'amber'], $status->cell($guest));
        self::assertSame('', ColumnTestSupport::find($attributes, 'expires')->value($owner));
    }

    public function testAnInvitationShowsItsNameEmailRoleContactAndExpiry(): void
    {
        $attributes = [...$this->columns(true)->attributes('identity.people')];
        $row = PeopleRow::invitation(new Invitation('sam@example.com', 'Sam Example', Role::Member, new Party(PartyKind::Person, 'Sam Contact', null, null, ''), 'u1', 'en', str_repeat('a', 64), new \DateTimeImmutable('2026-01-02')));

        self::assertSame('Sam Example', ColumnTestSupport::find($attributes, 'name')->value($row));
        self::assertSame('Sam Example', ColumnTestSupport::find($attributes, 'name')->cell($row));
        self::assertSame('sam@example.com', ColumnTestSupport::find($attributes, 'email')->value($row));
        self::assertSame(['text' => 'role.member', 'tone' => 'gray'], ColumnTestSupport::find($attributes, 'role')->cell($row));
        self::assertSame('Sam Contact', ColumnTestSupport::find($attributes, 'contact')->value($row));
        self::assertSame('2026-01-09', ColumnTestSupport::find($attributes, 'expires')->value($row));
        self::assertSame('2026-01-09', ColumnTestSupport::find($attributes, 'expires')->cell($row));
        self::assertSame('people.two_factor.off', ColumnTestSupport::find($attributes, 'two_factor')->value($row));
        self::assertSame(['text' => 'people.two_factor.off', 'tone' => 'gray'], ColumnTestSupport::find($attributes, 'two_factor')->cell($row));
        self::assertSame(['text' => 'people.status.deactivated', 'tone' => 'amber'], ColumnTestSupport::find($attributes, 'status')->cell($row));
        self::assertSame('people.status.deactivated', ColumnTestSupport::find($attributes, 'status')->value($row));
    }

    public function testTheContactIsALinkWhenTheAddressBookHasItsPageElseItsName(): void
    {
        $person = PeopleRow::person(new Person('m1', 'u1', 'Alex Example', 'alex@example.com', Role::Member, 'c1', 'Example Client', false, true));
        $without = PeopleRow::person(new Person('m2', 'u2', 'Sam Example', 'sam@example.com', Role::Member, null, null, false, true));
        $unnamed = PeopleRow::person(new Person('m3', 'u3', 'Kim Example', 'kim@example.com', Role::Member, 'c3', null, false, true));

        $linked = ColumnTestSupport::find($this->columns(true)->attributes('identity.people'), 'contact');
        self::assertSame(['links' => [['text' => 'Example Client', 'href' => '/contacts/c1']]], $linked->cell($person));
        self::assertNull($linked->cell($without));
        self::assertSame('Example Client', $linked->value($person));
        self::assertSame('', $linked->value($without));
        self::assertSame('', $linked->value($unnamed));
        self::assertSame(['links' => [['text' => '', 'href' => '/contacts/c3']]], $linked->cell($unnamed));

        $plain = ColumnTestSupport::find($this->columns(true, false)->attributes('identity.people'), 'contact');
        self::assertSame('Example Client', $plain->cell($person));
        self::assertNull($plain->cell($without));
    }

    private function columns(bool $picker = false, bool $pages = true): PeopleColumns
    {
        $pick = new class implements PersonContactPicker {
            public function searchUrl(): string
            {
                return '/contacts/search';
            }
        };
        $contacts = new class implements ContactPages {
            public function urlFor(string $id, array $query = []): string
            {
                return '/contacts/'.$id;
            }

            public function editUrlFor(string $id): string
            {
                return '/contacts/'.$id.'/edit';
            }

            public function newUrl(array $query = []): string
            {
                return '/contacts/new';
            }
        };

        return new PeopleColumns(ColumnTestSupport::translator(), $picker ? $pick : null, $pages ? $contacts : null);
    }
}
