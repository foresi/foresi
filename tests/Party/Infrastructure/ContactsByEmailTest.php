<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\Party\Infrastructure;

use Foresi\Party\Application\LinkContactPerson;
use Foresi\Party\Contract\ContactsByEmail;
use Foresi\Party\Contract\PartySummary;
use Foresi\Party\Domain\EmailPurpose;
use Foresi\Party\Domain\PartyEmail;
use Foresi\Party\Domain\PartyKind;
use Foresi\Tests\Party\Application\Query\ContactTestCase;

/**
 * Finding contacts by the address of a message, with nobody signed in as when mail is received.
 */
final class ContactsByEmailTest extends ContactTestCase
{
    public function testByAddressFindsTheContactWithThatAddressWithoutRegardToCase(): void
    {
        $company = $this->contact('Alpha Example s.r.o.', email: 'Office@Example.com');
        $this->contact('Beta Example s.r.o.', number: '87654321', email: 'other@example.com');

        $this->signOut();

        self::assertSame([$company->id()], $this->ids($this->finder()->byAddress('  OFFICE@example.COM ')));
        self::assertSame('Office@Example.com', $this->finder()->byAddress('office@example.com')[0]->email, 'The summary shows the address as it is stored.');
        self::assertSame([], $this->finder()->byAddress('nobody@example.com'));
        self::assertSame([], $this->finder()->byAddress('  '));
    }

    public function testByAddressFindsAnyPurposeAndEachPartyOnce(): void
    {
        $company = $this->contact('Alpha Example s.r.o.', email: 'office@example.com');
        $this->em->persist(new PartyEmail($company, 'invoices@example.com', EmailPurpose::Invoices));
        $this->em->persist(new PartyEmail($company, 'invoices@example.com', EmailPurpose::Copy));
        $other = $this->contact('Aardvark Example s.r.o.', number: '87654321', email: 'someone@example.com');
        $this->em->persist(new PartyEmail($other, 'invoices@example.com', EmailPurpose::Invoices));
        $this->em->flush();

        self::assertSame([$other->id(), $company->id()], $this->ids($this->finder()->byAddress('invoices@example.com')), 'By name, each once.');
    }

    public function testByAddressIgnoresArchivedContacts(): void
    {
        $this->contact('Old Example s.r.o.', email: 'old@example.com', archived: true);

        self::assertSame([], $this->finder()->byAddress('old@example.com'));
    }

    public function testByDomainFindsCompaniesAndSoleTradersNotPersons(): void
    {
        $company = $this->contact('Alpha Example s.r.o.', email: 'office@example.com');
        $trader = $this->contact('Tomas Example', PartyKind::SoleTrader, number: '87654321', email: 'tomas@EXAMPLE.com');
        $this->contact('Petra Example', PartyKind::Person, [], null, null, 'petra@example.com');

        $this->signOut();

        self::assertSame([$company->id(), $trader->id()], $this->ids($this->finder()->byDomain('Example.com')));
        self::assertSame([$company->id(), $trader->id()], $this->ids($this->finder()->byDomain('@example.com')));
        self::assertSame([], $this->finder()->byDomain(''));
    }

    public function testByDomainDoesNotMatchSubdomainsOrLongerDomainsAndIgnoresArchived(): void
    {
        $this->contact('Sub Example s.r.o.', number: '11111111', email: 'a@sub.example.com');
        $this->contact('Longer Example s.r.o.', number: '22222222', email: 'a@notexample.com');
        $this->contact('Old Example s.r.o.', number: '33333333', email: 'a@example.com', archived: true);
        $plain = $this->contact('Plain Example s.r.o.', number: '44444444', email: 'a@example.com');

        self::assertSame([$plain->id()], $this->ids($this->finder()->byDomain('example.com')));
        self::assertSame(['Sub Example s.r.o.'], array_map(static fn (PartySummary $s): string => $s->displayName, $this->finder()->byDomain('sub.example.com')));
    }

    public function testByDomainTakesWildcardsLiterally(): void
    {
        $this->contact('Alpha Example s.r.o.', email: 'a@example.com');
        $this->contact('Underscore Example s.r.o.', number: '87654321', email: 'a@ex_mple.com');

        self::assertSame([], $this->finder()->byDomain('%'));
        self::assertSame([], $this->finder()->byDomain('%.com'));
        self::assertSame([], $this->finder()->byDomain('ex_mple.co'));
        self::assertSame([], $this->finder()->byDomain('e_ample.com'), 'An underscore is not a wildcard.');
        self::assertCount(1, $this->finder()->byDomain('ex_mple.com'));
        self::assertSame([], $this->finder()->byDomain('\\'));
    }

    public function testCompaniesOfPersonListsTheLinkedCompaniesInTheOrderLinkedAndWorksWithNobodySignedIn(): void
    {
        $person = $this->contact('Petra Example', PartyKind::Person, [], null);
        $first = $this->contact('Alpha Example s.r.o.');
        $second = $this->contact('Zeta Example s.r.o.', number: '87654321');
        $archived = $this->contact('Old Example s.r.o.', number: '11111111', archived: true);
        $link = self::getContainer()->get(LinkContactPerson::class);
        $link->link($second, $person, 'Director', true);
        $link->link($first, $person, '', false);
        $link->link($archived, $person, '', false);
        $this->em->flush();

        $this->signOut();
        $companies = $this->finder()->companiesOfPerson($person->id());

        self::assertSame([$second->id(), $first->id(), $archived->id()], $this->ids($companies));
        self::assertSame([false, false, true], array_map(static fn (PartySummary $s): bool => $s->archived, $companies));
        self::assertSame([], $this->finder()->companiesOfPerson($first->id()), 'A company has no companies.');
        self::assertSame([], $this->finder()->companiesOfPerson('0192f1a4-0000-7000-8000-000000000000'));
    }

    private function finder(): ContactsByEmail
    {
        return self::getContainer()->get(ContactsByEmail::class);
    }

    /**
     * @param list<PartySummary> $summaries
     *
     * @return list<string>
     */
    private function ids(array $summaries): array
    {
        return array_map(static fn (PartySummary $s): string => $s->id, $summaries);
    }
}
