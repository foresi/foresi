<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\Party\Infrastructure;

use Foresi\Party\Application\LinkContactPerson;
use Foresi\Party\Contract\ContactPersonRefused;
use Foresi\Party\Contract\ContactPersonWrites;
use Foresi\Party\Contract\PartyDirectory;
use Foresi\Party\Contract\PartyPersonSummary;
use Foresi\Party\Contract\UnknownParty;
use Foresi\Party\Domain\ContactLink;
use Foresi\Party\Domain\Party;
use Foresi\Party\Domain\PartyKind;
use Foresi\Tests\Party\Application\Query\ContactTestCase;

/**
 * Contact persons added from another module through the contract: a person of the
 * address book linked, or a new one written and linked, with the rules of the
 * contact's own dialog.
 */
final class ContactPersonWritesTest extends ContactTestCase
{
    public function testCandidatesAreThePersonsTheCompanyDoesNotListYet(): void
    {
        $company = $this->contact('Alpha Example s.r.o.');
        $petra = $this->contact('Petra Example', PartyKind::Person, [], null, null, 'petra@example.com');
        $karel = $this->contact('Karel Example', PartyKind::Person, [], null);
        $this->contact('Old Example', PartyKind::Person, [], null, archived: true);
        self::getContainer()->get(LinkContactPerson::class)->link($company, $karel, '', false);

        self::assertEquals([new PartyPersonSummary($petra->id(), 'Petra Example', 'petra@example.com', '+420 000 000 000', null)], $this->writes()->candidatesOf($company->id()));
        self::assertSame([], $this->writes()->candidatesOf($petra->id()), 'A person takes no contact persons.');
        self::assertSame([], $this->writes()->candidatesOf('0192f1a4-0000-7000-8000-000000000000'));
        $this->assertRefusedForGuestAndNobody(fn (): array => $this->writes()->candidatesOf($company->id()));
    }

    public function testACandidateIsLinkedAndTheFirstBecomesPrimary(): void
    {
        $company = $this->contact('Alpha Example s.r.o.');
        $petra = $this->contact('Petra Example', PartyKind::Person, [], null, null, 'petra@example.com');
        $karel = $this->contact('Karel Example', PartyKind::Person, [], null);

        $added = $this->writes()->link($company->id(), $petra->id(), ' Director ');
        $this->em->flush();
        self::assertEquals(new PartyPersonSummary($petra->id(), 'Petra Example', 'petra@example.com', '+420 000 000 000', 'Director'), $added);
        $this->writes()->link($company->id(), $karel->id(), '');
        $this->em->flush();

        self::assertEquals([$added, new PartyPersonSummary($karel->id(), 'Karel Example', null, '+420 000 000 000', null)], $this->directory()->personsOf($company->id()));
        self::assertSame([true, false], [$this->link($company, $petra)->isPrimary(), $this->link($company, $karel)->isPrimary()]);

        $this->assertRefused(['person' => ContactPersonRefused::PERSON], fn (): PartyPersonSummary => $this->writes()->link($company->id(), $petra->id(), ''));
        $this->assertRefused(['person' => ContactPersonRefused::PERSON], fn (): PartyPersonSummary => $this->writes()->link($company->id(), $company->id(), ''));
        $other = $this->contact('Eva Example', PartyKind::Person, [], null);
        $this->assertRefused(['position' => ContactPersonRefused::TOO_LONG], fn (): PartyPersonSummary => $this->writes()->link($company->id(), $other->id(), str_repeat('a', 101)));
        $this->expectException(UnknownParty::class);
        $this->writes()->link('0192f1a4-0000-7000-8000-000000000000', $other->id(), '');
    }

    public function testANewPersonIsWrittenAndLinked(): void
    {
        $company = $this->contact('Alpha Example s.r.o.');

        $added = $this->writes()->addNew($company->id(), ' Eva Example ', ' eva@example.com ', 'Buyer');
        $this->em->flush();

        self::assertSame(['Eva Example', 'eva@example.com', null, 'Buyer'], [$added->displayName, $added->email, $added->phone, $added->position]);
        self::assertEquals([$added], $this->directory()->personsOf($company->id()));
        $person = $this->em->find(Party::class, $added->id);
        self::assertInstanceOf(Party::class, $person);
        self::assertTrue($this->link($company, $person)->isPrimary());

        $this->assertRefused(['name' => ContactPersonRefused::REQUIRED, 'email' => ContactPersonRefused::EMAIL, 'position' => ContactPersonRefused::TOO_LONG], fn (): PartyPersonSummary => $this->writes()->addNew($company->id(), '', 'not-an-address', str_repeat('a', 101)));
        $this->assertRefused(['person' => ContactPersonRefused::PERSON], fn (): PartyPersonSummary => $this->writes()->addNew($person->id(), 'Ivo Example', '', ''));
        self::assertCount(1, $this->directory()->personsOf($company->id()));
    }

    /**
     * @param array<string, string> $reasons
     * @param \Closure(): mixed     $call
     */
    private function assertRefused(array $reasons, \Closure $call): void
    {
        try {
            $call();
        } catch (ContactPersonRefused $refused) {
            self::assertSame($reasons, $refused->reasons);

            return;
        }
        self::fail('Expected the contact person to be refused.');
    }

    private function link(Party $company, Party $person): ContactLink
    {
        $link = $this->em->getRepository(ContactLink::class)->findOneBy(['company' => $company, 'person' => $person]);
        self::assertInstanceOf(ContactLink::class, $link);

        return $link;
    }

    private function writes(): ContactPersonWrites
    {
        return self::getContainer()->get(ContactPersonWrites::class);
    }

    private function directory(): PartyDirectory
    {
        return self::getContainer()->get(PartyDirectory::class);
    }
}
