<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\Party\Infrastructure;

use Foresi\Party\Application\LinkContactPerson;
use Foresi\Party\Contract\Event\ContactKind;
use Foresi\Party\Contract\PartyDirectory;
use Foresi\Party\Contract\PartyPersonSummary;
use Foresi\Party\Contract\UnknownParty;
use Foresi\Party\Domain\Address;
use Foresi\Party\Domain\AddressType;
use Foresi\Party\Domain\PartyAddress;
use Foresi\Party\Domain\PartyKind;
use Foresi\Party\Domain\PartyRole;
use Foresi\Tests\Party\Application\Query\ContactTestCase;

/**
 * Contacts as other modules read them, through the contract and not the
 * address book's own classes.
 */
final class PartyDirectoryTest extends ContactTestCase
{
    public function testGetReturnsSummaryForCompanyPersonAndSoleTrader(): void
    {
        $company = $this->contact('Alpha Example s.r.o.', PartyKind::Company, [PartyRole::Customer, PartyRole::Supplier], '12345679', 'CZ12345679', 'office@example.com');
        $this->em->persist(new PartyAddress($company, AddressType::Delivery, new Address('Other Street 2', 'Other City', '200 00', 'CZ')));
        $this->em->flush();
        $person = $this->contact('Petra Example', PartyKind::Person, [PartyRole::Partner], null);
        $trader = $this->contact('Tomas Example', PartyKind::SoleTrader, [PartyRole::Customer], '87654321', null, 'tomas@example.com');

        $summary = $this->directory()->get($company->id());
        self::assertSame($company->id(), $summary->id);
        self::assertSame(ContactKind::Company, $summary->kind);
        self::assertSame('Alpha Example s.r.o.', $summary->displayName);
        self::assertSame('12345679', $summary->registrationNumber);
        self::assertSame('CZ12345679', $summary->vatId);
        self::assertSame(['Example Street 1', '100 00 Example City', 'CZ'], $summary->addressLines);
        self::assertSame('office@example.com', $summary->email);
        self::assertSame('+420 000 000 000', $summary->phone);
        self::assertSame(['customer', 'supplier'], $summary->roles);
        self::assertFalse($summary->archived);

        $summary = $this->directory()->get($person->id());
        self::assertSame(ContactKind::Person, $summary->kind);
        self::assertNull($summary->registrationNumber);
        self::assertNull($summary->vatId);
        self::assertNull($summary->email);
        self::assertSame(['partner'], $summary->roles);

        $summary = $this->directory()->get($trader->id());
        self::assertSame(ContactKind::SoleTrader, $summary->kind);
        self::assertSame('87654321', $summary->registrationNumber);
        self::assertSame('tomas@example.com', $summary->email);
    }

    public function testGetUnknownThrowsUnknownParty(): void
    {
        self::assertNull($this->directory()->find('0192f1a4-0000-7000-8000-000000000000'));
        self::assertNull($this->directory()->find('not-an-id'));

        $this->expectException(UnknownParty::class);
        $this->directory()->get('0192f1a4-0000-7000-8000-000000000000');
    }

    public function testArchivedPartyIsFoundAndFlagged(): void
    {
        $archived = $this->contact('Old Example s.r.o.', archived: true);

        $summary = $this->directory()->find($archived->id());

        self::assertNotNull($summary);
        self::assertTrue($summary->archived);
    }

    public function testPersonsOfListsLinkedPersonsWithPosition(): void
    {
        $company = $this->contact('Alpha Example s.r.o.');
        $first = $this->contact('Petra Example', PartyKind::Person, [], null, null, 'petra@example.com');
        $second = $this->contact('Karel Example', PartyKind::Person, [], null);
        $link = self::getContainer()->get(LinkContactPerson::class);
        $link->link($company, $first, 'Director', true);
        $link->link($company, $second, '', false);

        $persons = $this->directory()->personsOf($company->id());

        self::assertEquals([
            new PartyPersonSummary($first->id(), 'Petra Example', 'petra@example.com', '+420 000 000 000', 'Director'),
            new PartyPersonSummary($second->id(), 'Karel Example', null, '+420 000 000 000', null),
        ], $persons);
    }

    public function testPersonsOfOfAPersonReturnsEmpty(): void
    {
        $company = $this->contact('Alpha Example s.r.o.');
        $person = $this->contact('Petra Example', PartyKind::Person, [], null);
        self::getContainer()->get(LinkContactPerson::class)->link($company, $person, 'Director', true);

        self::assertSame([], $this->directory()->personsOf($person->id()));
    }

    private function directory(): PartyDirectory
    {
        return self::getContainer()->get(PartyDirectory::class);
    }
}
