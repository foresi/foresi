<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\Inbox\Application;

use Foresi\Inbox\Application\SenderMatching;
use Foresi\Inbox\Domain\MatchBasis;
use Foresi\Party\Contract\ContactsByEmail;
use Foresi\Party\Contract\Event\ContactKind;
use Foresi\Party\Contract\PartySummary;
use Foresi\Shared\Domain\Id;
use Foresi\Tests\Inbox\Support\FakeContactsByEmail;
use PHPUnit\Framework\TestCase;

/**
 * The three steps of matching a sender to the address book, and
 * that it never guesses.
 */
final class SenderMatchingTest extends TestCase
{
    private FakeContactsByEmail $contacts;
    private SenderMatching $matching;

    protected function setUp(): void
    {
        $this->contacts = new FakeContactsByEmail();
        $this->matching = new SenderMatching($this->contacts);
    }

    public function testAnExactAddressMatchesItsContact(): void
    {
        $company = $this->contacts->add('Client A s.r.o.', ContactKind::Company, ['office@client-a.example.com']);

        $match = $this->matching->match('Office@Client-A.example.com', false);

        self::assertSame($company, $match->contactId);
        self::assertNull($match->companyId);
        self::assertSame(MatchBasis::Address, $match->basis);
    }

    public function testAPersonWithOneCompanyGivesTheCompany(): void
    {
        $person = $this->contacts->add('Petra Example', ContactKind::Person, ['petra@client-a.example.com']);
        $company = $this->contacts->add('Client A s.r.o.', ContactKind::Company, []);
        $this->contacts->link($company, $person);

        $match = $this->matching->match('petra@client-a.example.com', false);

        self::assertSame($person, $match->contactId);
        self::assertSame($company, $match->companyId);
        self::assertSame(MatchBasis::Address, $match->basis);
    }

    public function testAPersonWithTwoCompaniesGivesNone(): void
    {
        $person = $this->contacts->add('Petra Example', ContactKind::Person, ['petra@example.com']);
        $this->contacts->link($this->contacts->add('Client A s.r.o.', ContactKind::Company, []), $person);
        $this->contacts->link($this->contacts->add('Client B s.r.o.', ContactKind::Company, []), $person);

        $match = $this->matching->match('petra@example.com', true);

        self::assertSame($person, $match->contactId, 'The person still matches.');
        self::assertNull($match->companyId);
    }

    public function testAnArchivedCompanyOfAPersonDoesNotCount(): void
    {
        $person = $this->contacts->add('Petra Example', ContactKind::Person, ['petra@example.com']);
        $this->contacts->link($this->contacts->add('Old Client s.r.o.', ContactKind::Company, [], archived: true), $person);
        $company = $this->contacts->add('Client A s.r.o.', ContactKind::Company, []);
        $this->contacts->link($company, $person);

        self::assertSame($company, $this->matching->match('petra@example.com', false)->companyId);
    }

    public function testTheDomainMatchesOnlyWhenTheSenderIsVerified(): void
    {
        $company = $this->contacts->add('Client A s.r.o.', ContactKind::Company, ['office@client-a.example.com']);

        $verified = $this->matching->match('new.person@client-a.example.com', true);
        $unverified = $this->matching->match('new.person@client-a.example.com', false);

        self::assertSame($company, $verified->contactId);
        self::assertNull($verified->companyId);
        self::assertSame(MatchBasis::Domain, $verified->basis);
        self::assertFalse($unverified->matched());
        self::assertNull($unverified->basis);
    }

    public function testAFreeMailDomainNeverMatchesByDomain(): void
    {
        $this->contacts->add('Tomas Example', ContactKind::SoleTrader, ['tomas.example@gmail.com']);
        $this->contacts->add('Jana Example', ContactKind::SoleTrader, ['jana.example@seznam.cz']);

        self::assertFalse($this->matching->match('someone.else@gmail.com', true)->matched());
        self::assertFalse($this->matching->match('someone.else@seznam.cz', true)->matched());
        self::assertTrue($this->matching->match('tomas.example@gmail.com', false)->matched(), 'The exact address still matches.');
    }

    public function testTwoCompaniesOnADomainGiveNone(): void
    {
        $this->contacts->add('Client A s.r.o.', ContactKind::Company, ['office@example.com']);
        $this->contacts->add('Client B s.r.o.', ContactKind::Company, ['billing@example.com']);

        self::assertFalse($this->matching->match('new.person@example.com', true)->matched());
    }

    public function testTwoContactsWithTheAddressGiveNoneAndTheDomainIsNotTried(): void
    {
        $this->contacts->add('Client A s.r.o.', ContactKind::Company, ['shared@client-a.example.com']);
        $this->contacts->add('Petra Example', ContactKind::Person, ['shared@client-a.example.com']);

        self::assertFalse($this->matching->match('shared@client-a.example.com', true)->matched());
    }

    public function testArchivedPartiesAreIgnored(): void
    {
        $this->contacts->add('Old Client s.r.o.', ContactKind::Company, ['office@old.example.com'], archived: true);
        $this->contacts->add('Client A s.r.o.', ContactKind::Company, ['office@client-a.example.com']);
        $current = $this->contacts->add('Client A s.r.o.', ContactKind::Company, ['shared@client-a.example.com']);
        $this->contacts->add('Old Client A s.r.o.', ContactKind::Company, ['shared@client-a.example.com'], archived: true);

        self::assertFalse($this->matching->match('office@old.example.com', true)->matched());
        self::assertSame($current, $this->matching->match('shared@client-a.example.com', false)->contactId, 'An archived contact with the same address is no second match.');
    }

    public function testNoAddressMatchesNothing(): void
    {
        self::assertFalse($this->matching->match(null, true)->matched());
        self::assertFalse($this->matching->match('not an address', true)->matched());
        self::assertFalse($this->matching->match('@example.com', true)->matched());
    }

    public function testTheAddressBookIsAskedForTheAddressTrimmedAndInLowerCaseBeyondAscii(): void
    {
        $contacts = $this->createMock(ContactsByEmail::class);
        $contacts->expects(self::once())->method('byAddress')->with('žlutá@client-a.example.com')->willReturn([]);
        $contacts->expects(self::once())->method('byDomain')->with('client-a.example.com')->willReturn([]);

        new SenderMatching($contacts)->match("  ŽLUTÁ@Client-A.example.com \n", true);
    }

    public function testAnAddressWithoutAWholeLocalPartAndDomainIsNeverLookedUp(): void
    {
        $contacts = $this->createMock(ContactsByEmail::class);
        $contacts->expects(self::never())->method('byAddress');
        $contacts->expects(self::never())->method('byDomain');
        $matching = new SenderMatching($contacts);

        foreach (['@client-a.example.com', 'petra@', 'petra@ ', 'client-a.example.com', ''] as $address) {
            self::assertFalse($matching->match($address, true)->matched(), $address);
        }
    }

    public function testAContactThatIsNoPersonIsNotAskedForCompanies(): void
    {
        $company = new PartySummary(Id::generate(), ContactKind::Company, 'Client A s.r.o.', null, null, [], null, null, [], false);
        $contacts = $this->createMock(ContactsByEmail::class);
        $contacts->expects(self::never())->method('companiesOfPerson');

        self::assertNull(new SenderMatching($contacts)->companyOf($company));
    }
}
