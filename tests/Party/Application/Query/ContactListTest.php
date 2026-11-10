<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Tests\Party\Application\Query;

use App\Party\Application\PartyAccess;
use App\Party\Application\PartyQuery;
use App\Party\Application\Query\ContactList;
use App\Party\Application\Query\ContactQueries;
use App\Party\Application\Query\ContactRow;
use App\Party\Application\Query\ContactSlice;
use App\Party\Application\Query\ContactSliceFilter;
use App\Party\Domain\Address;
use App\Party\Domain\EmailPurpose;
use App\Party\Domain\PartyEmail;
use App\Party\Domain\PartyKind;
use App\Party\Domain\PartyRole;
use App\Shared\Domain\Id;

final class ContactListTest extends ContactTestCase
{
    public function testGuestsAndNobodyAreRefusedByEveryRead(): void
    {
        $this->contact('Alpha Example');

        $this->assertRefusedForGuestAndNobody(fn (): ContactSlice => $this->list()->slice(new ContactSliceFilter()));
        $this->assertRefusedForGuestAndNobody(fn (): \App\Party\Application\PartyPage => $this->list()->search('', null, false, 'name', 1));
        $this->assertRefusedForGuestAndNobody(fn (): \App\Party\Application\PartyCounts => $this->list()->counts());
    }

    public function testOwnersAndMembersRead(): void
    {
        $this->contact('Alpha Example');

        foreach ([$this->owner, $this->member] as $staff) {
            $this->signIn($staff);
            self::assertSame(['Alpha Example'], $this->names($this->list()->slice(new ContactSliceFilter())));
            self::assertSame(1, $this->list()->search('', null, false, 'name', 1)->total);
            self::assertSame(1, $this->list()->counts()->all);
        }
    }

    public function testASliceIsNewestFirstAndKeepsOutArchivedAndOtherTenants(): void
    {
        $this->contact('First Example');
        $this->contact('Archived Example', archived: true);
        $this->contact('Second Example');
        $this->foreignContact('Foreign Example');
        $this->contact('Third Example');

        $slice = $this->list()->slice(new ContactSliceFilter());

        self::assertSame(['Third Example', 'Second Example', 'First Example'], $this->names($slice));
        self::assertFalse($slice->hasMore);
    }

    public function testAnArchivedFilterListsTheArchivedAlone(): void
    {
        $this->contact('Active Example');
        $this->contact('Archived Example', archived: true);

        // The contact the guest represents is archived, so it is out of the other reads.
        self::assertSame(['Archived Example', 'Example Client'], $this->names($this->list()->slice(new ContactSliceFilter(archived: true))));
        self::assertSame(['Active Example'], $this->names($this->list()->slice(new ContactSliceFilter(archived: false))));
    }

    public function testTheRowTellsWhatTheListShows(): void
    {
        $party = $this->contact('Alpha Example s.r.o.', roles: [PartyRole::Customer, PartyRole::Supplier], number: '123 456 79', vatId: 'CZ12345679', email: 'info@example.com');
        $this->em->persist(new PartyEmail($party, 'billing@example.com', EmailPurpose::Invoices));
        $this->em->flush();

        $row = $this->list()->slice(new ContactSliceFilter())->rows[0];

        self::assertSame($party->id(), $row->id);
        self::assertSame(PartyKind::Company, $row->kind);
        self::assertSame('Alpha Example s.r.o.', $row->name);
        self::assertSame([PartyRole::Customer, PartyRole::Supplier], $row->roles);
        self::assertSame('CZ', $row->registrationCountry);
        self::assertSame('123 456 79', $row->registrationNumber);
        self::assertSame('CZ12345679', $row->vatId);
        self::assertSame('info@example.com', $row->email);
        self::assertEquals(new Address('Example Street 1', 'Example City', '100 00', 'CZ'), $row->billingAddress);
        self::assertFalse($row->archived);
    }

    public function testAContactWithoutIdentifiersOrAGeneralAddressHasNone(): void
    {
        $party = $this->contact('Plain Example', PartyKind::Person, number: null);
        $this->em->persist(new PartyEmail($party, 'billing@example.com', EmailPurpose::Invoices));
        $this->em->flush();

        $row = $this->list()->slice(new ContactSliceFilter())->rows[0];

        self::assertSame(PartyKind::Person, $row->kind);
        self::assertNull($row->registrationCountry);
        self::assertNull($row->registrationNumber);
        self::assertNull($row->vatId);
        self::assertNull($row->email);
    }

    public function testTheTextMatchesTheNameTheNumbersAndTheEmail(): void
    {
        $this->contact('Alpha Example', number: '11111119', vatId: 'CZ11111119', email: 'alpha@example.com');
        $this->contact('Beta Example', number: '22222229', vatId: 'CZ22222229', email: 'beta@example.com');

        self::assertSame(['Beta Example'], $this->names($this->list()->slice(new ContactSliceFilter(text: 'BETA'))));
        self::assertSame(['Beta Example'], $this->names($this->list()->slice(new ContactSliceFilter(text: '2222 2229'))));
        self::assertSame(['Beta Example'], $this->names($this->list()->slice(new ContactSliceFilter(text: 'cz 22222229'))));
        self::assertSame(['Alpha Example'], $this->names($this->list()->slice(new ContactSliceFilter(text: 'alpha@example'))));
        self::assertSame([], $this->names($this->list()->slice(new ContactSliceFilter(text: 'gamma'))));
        self::assertSame(['Beta Example', 'Alpha Example'], $this->names($this->list()->slice(new ContactSliceFilter(text: '  Example  '))));
    }

    public function testALongTextIsCutAtTheLengthOfTheScreenSearch(): void
    {
        $name = str_repeat('a', 100);
        $this->contact($name);

        self::assertSame([$name], $this->names($this->list()->slice(new ContactSliceFilter(text: $name.'zzzzzz'))));
    }

    public function testTheRoleNarrowsTheList(): void
    {
        $this->contact('Customer Example', roles: [PartyRole::Customer]);
        $this->contact('Supplier Example', roles: [PartyRole::Supplier]);
        $this->contact('Both Example', roles: [PartyRole::Customer, PartyRole::Supplier]);
        $this->contact('Plain Example', roles: []);

        self::assertSame(['Both Example', 'Customer Example'], $this->names($this->list()->slice(new ContactSliceFilter(role: PartyRole::Customer))));
        self::assertSame(['Both Example', 'Supplier Example'], $this->names($this->list()->slice(new ContactSliceFilter(role: PartyRole::Supplier))));
        self::assertCount(4, $this->list()->slice(new ContactSliceFilter())->rows);
    }

    public function testTheRegistrationNumberAndTheVatIdFindExactlyOne(): void
    {
        $this->contact('Alpha Example', number: '11111119', vatId: 'CZ11111119');
        $this->contact('Beta Example', number: '22222229', vatId: 'CZ22222229');
        $this->contact('Gamma Example', number: '12222229', vatId: 'CZ12222229');

        self::assertSame(['Beta Example'], $this->names($this->list()->slice(new ContactSliceFilter(registrationNumber: '2222 2229'))));
        self::assertSame(['Beta Example'], $this->names($this->list()->slice(new ContactSliceFilter(vatId: 'cz 2222 2229'))));
        self::assertSame([], $this->names($this->list()->slice(new ContactSliceFilter(registrationNumber: '222222'))));
        self::assertSame([], $this->names($this->list()->slice(new ContactSliceFilter(vatId: 'CZ2222'))));
        self::assertSame(['Beta Example'], $this->names($this->list()->slice(new ContactSliceFilter(registrationNumber: '22222229', vatId: 'CZ22222229'))));
        self::assertSame([], $this->names($this->list()->slice(new ContactSliceFilter(registrationNumber: '22222229', vatId: 'CZ11111119'))));
    }

    public function testTheCursorExcludesItsOwnRowAndOnlyOlderOnesFollow(): void
    {
        $first = $this->contact('First Example');
        $second = $this->contact('Second Example');
        $third = $this->contact('Third Example');

        self::assertSame(['Second Example', 'First Example'], $this->names($this->list()->slice(new ContactSliceFilter(), $third->id())));
        self::assertSame(['First Example'], $this->names($this->list()->slice(new ContactSliceFilter(), $second->id())));
        self::assertSame([], $this->names($this->list()->slice(new ContactSliceFilter(), $first->id())));
    }

    public function testAFurtherSliceIsKnownByOneRowMoreThanTheLimit(): void
    {
        $this->contact('First Example');
        $this->contact('Second Example');
        $this->contact('Third Example');

        $slice = $this->list()->slice(new ContactSliceFilter(), null, 2);
        self::assertSame(['Third Example', 'Second Example'], $this->names($slice));
        self::assertTrue($slice->hasMore);
        self::assertSame($slice->rows[1]->id, $slice->lastId());

        $next = $this->list()->slice(new ContactSliceFilter(), $slice->lastId(), 2);
        self::assertSame(['First Example'], $this->names($next));
        self::assertFalse($next->hasMore);

        $exact = $this->list()->slice(new ContactSliceFilter(), null, 3);
        self::assertCount(3, $exact->rows);
        self::assertFalse($exact->hasMore, 'A slice that ends with the last row has no more.');
    }

    public function testAnEmptySliceHasNoLastId(): void
    {
        $slice = $this->list()->slice(new ContactSliceFilter(text: 'nobody'));

        self::assertSame([], $slice->rows);
        self::assertNull($slice->lastId());
    }

    public function testACursorThatIsNoIdListsNothingAndAsksNoOne(): void
    {
        $queries = new class implements ContactQueries {
            public int $calls = 0;

            public function after(ContactSliceFilter $filter, ?string $afterId, int $limit): array
            {
                ++$this->calls;

                return [];
            }
        };
        $list = new ContactList(self::getContainer()->get(PartyAccess::class), self::getContainer()->get(PartyQuery::class), $queries);

        $slice = $list->slice(new ContactSliceFilter(), 'not-an-id');

        self::assertSame([], $slice->rows);
        self::assertFalse($slice->hasMore);
        self::assertSame(0, $queries->calls);
        $list->slice(new ContactSliceFilter(), Id::generate());
        self::assertSame(1, $queries->calls);
    }

    public function testTheLimitIsKeptBetweenOneAndTheMaximum(): void
    {
        $queries = new class implements ContactQueries {
            /** @var list<int> */
            public array $limits = [];

            public function after(ContactSliceFilter $filter, ?string $afterId, int $limit): array
            {
                $this->limits[] = $limit;

                return [];
            }
        };
        $list = new ContactList(self::getContainer()->get(PartyAccess::class), self::getContainer()->get(PartyQuery::class), $queries);

        $list->slice(new ContactSliceFilter());
        $list->slice(new ContactSliceFilter(), null, 0);
        $list->slice(new ContactSliceFilter(), null, -5);
        $list->slice(new ContactSliceFilter(), null, 1);
        $list->slice(new ContactSliceFilter(), null, 100);
        $list->slice(new ContactSliceFilter(), null, 101);
        $list->slice(new ContactSliceFilter(), null, 5000);

        self::assertSame([26, 2, 2, 2, 101, 101, 101], $queries->limits);
    }

    public function testTheScreenSearchGoesThroughTheSameGuard(): void
    {
        $this->contact('Alpha Example', roles: [PartyRole::Customer]);
        $this->contact('Beta Example', roles: [PartyRole::Supplier], archived: true);

        $page = $this->list()->search('', PartyRole::Supplier, true, 'name', 1);
        $counts = $this->list()->counts();

        self::assertSame(['Beta Example'], array_map(static fn (\App\Party\Application\PartyRow $row): string => $row->displayName, $page->rows));
        self::assertSame([1, 1, 0, 0, 2], [$counts->all, $counts->customers, $counts->suppliers, $counts->unreliablePayers, $counts->archived]);
    }

    private function list(): ContactList
    {
        return self::getContainer()->get(ContactList::class);
    }

    /**
     * @return list<string>
     */
    private function names(ContactSlice $slice): array
    {
        return array_map(static fn (ContactRow $row): string => $row->name, $slice->rows);
    }
}
