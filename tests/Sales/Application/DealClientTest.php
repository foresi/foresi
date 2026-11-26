<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\Sales\Application;

use Foresi\Party\Contract\ContactPersonRefused;
use Foresi\Party\Contract\PartyDirectory;
use Foresi\Party\Contract\PartyPersonSummary;
use Foresi\Party\Domain\ContactLink;
use Foresi\Party\Domain\Party;
use Foresi\Party\Domain\PartyKind;
use Foresi\Sales\Application\Api\AddDealLinkInput;
use Foresi\Sales\Application\Api\CreateDealInput;
use Foresi\Sales\Application\Api\RemoveDealLinkInput;
use Foresi\Sales\Application\Api\UpdateDealInput;
use Foresi\Sales\Application\ContactPersonDraft;
use Foresi\Sales\Domain\Deal;
use Foresi\Tests\Sales\Support\SalesTestCase;

/**
 * The client of a deal changes, taking contact persons of the new client or none, unless
 * the deal is linked to other deals; and the deal's dialog adds a person to the client on
 * the way, from the address book or new, or stores nothing when the person is refused.
 */
final class DealClientTest extends SalesTestCase
{
    private Party $beta;
    private Party $tomas;

    protected function setUp(): void
    {
        parent::setUp();
        $this->beta = $this->company('Beta Example a.s.');
        $this->tomas = $this->contactPerson($this->beta, 'Tomas Example');
    }

    public function testAnotherClientTakesItsOwnContactPersonsOrNone(): void
    {
        $deal = $this->deal(contactPersonIds: [$this->petra->id()]);
        $this->clock->modify('+1 hour');

        $moved = $this->deals()->update(new UpdateDealInput($deal->id(), contactPersonIds: [$this->tomas->id()], clientPartyId: $this->beta->id()));

        self::assertSame($this->beta->id(), $moved->clientPartyId());
        self::assertEquals(new \DateTimeImmutable('2026-11-23 11:00:00 UTC'), $moved->updatedAt());
        self::assertSame([$this->tomas->id()], $this->reads()->deal($deal->id())->contactPersonIds);
        self::assertSame($this->beta->id(), $this->reload(Deal::class, $deal->id())->clientPartyId());
        self::assertSame([['deal_id' => $deal->id(), 'status' => 'open', 'stage_id' => $this->stages['inquiry']->id(), 'custom_fields' => []]], $this->events('sales.deal.updated'));

        // Back to the first client without persons: the deal has none.
        $this->deals()->update(new UpdateDealInput($deal->id(), clientPartyId: $this->client->id()));
        self::assertSame($this->client->id(), $this->reload(Deal::class, $deal->id())->clientPartyId());
        self::assertSame([], $this->reads()->deal($deal->id())->contactPersonIds);
    }

    public function testTheSameClientChangesNothing(): void
    {
        $deal = $this->deal(contactPersonIds: [$this->petra->id()]);

        $this->deals()->update(new UpdateDealInput($deal->id(), clientPartyId: $this->client->id()));
        $this->client->archive(new \DateTimeImmutable('2026-11-23 09:00:00'));
        $this->em->flush();
        // Even archived since: the client is not changed, so it is not checked.
        $this->deals()->update(new UpdateDealInput($deal->id(), title: 'Roof audit, phase 1', clientPartyId: $this->client->id()));

        self::assertSame([$this->petra->id()], $this->reads()->deal($deal->id())->contactPersonIds);
        self::assertSame('Roof audit, phase 1', $this->reload(Deal::class, $deal->id())->title());
    }

    public function testAnUnknownOrArchivedClientOrAnotherClientsPersonsAreRefused(): void
    {
        $deal = $this->deal(contactPersonIds: [$this->petra->id()]);
        $archived = $this->company('Gamma Example s.r.o.', archived: true);

        $refused = $this->assertRefused('client_unknown', fn (): Deal => $this->deals()->update(new UpdateDealInput($deal->id(), clientPartyId: '0192f1a4-0000-7000-8000-000000000000')));
        self::assertSame('clientPartyId', $refused->field());
        $refused = $this->assertRefused('client_archived', fn (): Deal => $this->deals()->update(new UpdateDealInput($deal->id(), clientPartyId: $archived->id())));
        self::assertSame('clientPartyId', $refused->field());
        // The persons given are checked against the new client.
        $refused = $this->assertRefused('contact_person_not_of_client', fn (): Deal => $this->deals()->update(new UpdateDealInput($deal->id(), title: 'Renamed', contactPersonIds: [$this->petra->id()], clientPartyId: $this->beta->id())));
        self::assertSame('contactPersonIds', $refused->field());

        $kept = $this->reload(Deal::class, $deal->id());
        self::assertSame([$this->client->id(), 'Roof audit'], [$kept->clientPartyId(), $kept->title()]);
        self::assertSame([$this->petra->id()], $this->reads()->deal($deal->id())->contactPersonIds);
        self::assertSame([], $this->events('sales.deal.updated'));
    }

    public function testALinkedDealKeepsItsClientUntilTheLinksAreRemoved(): void
    {
        $audit = $this->deal('Roof audit');
        $repair = $this->deal('Gutter repair');
        $link = $this->links()->add(new AddDealLinkInput($repair->id(), $audit->id()));

        foreach ([$audit, $repair] as $deal) {
            $refused = $this->assertRefused('client_has_links', fn (): Deal => $this->deals()->update(new UpdateDealInput($deal->id(), clientPartyId: $this->beta->id())));
            self::assertSame([409, 'clientPartyId'], [$refused->kind()->status(), $refused->field()]);
        }
        self::assertSame($this->client->id(), $this->reload(Deal::class, $audit->id())->clientPartyId());

        $this->links()->remove(new RemoveDealLinkInput($repair->id(), $link->id()));
        self::assertSame($this->beta->id(), $this->deals()->update(new UpdateDealInput($repair->id(), clientPartyId: $this->beta->id()))->clientPartyId());
    }

    public function testAPersonOfTheAddressBookIsLinkedToTheClientAndAdded(): void
    {
        $jana = $this->loosePerson('Jana Example');

        $deal = $this->deals()->create(new CreateDealInput('Roof audit', $this->client->id(), contactPersonIds: [$this->petra->id()]), ContactPersonDraft::existing($jana->id(), ' Buyer '));

        self::assertSame([$this->petra->id(), $jana->id()], $this->reads()->deal($deal->id())->contactPersonIds);
        $linked = $this->personOf($this->client, $jana);
        self::assertSame('Buyer', $linked->position);
        // The client had no primary contact person, so the person added becomes it.
        self::assertTrue($this->link($this->client, $jana)->isPrimary());

        // A person linked already is no candidate.
        $this->expectRefusedPerson(['person' => ContactPersonRefused::PERSON], fn (): Deal => $this->deals()->update(new UpdateDealInput($deal->id()), ContactPersonDraft::existing($this->karel->id())));
    }

    public function testANewPersonIsCreatedForTheNewClientAndAdded(): void
    {
        $deal = $this->deal(contactPersonIds: [$this->petra->id()]);
        $this->deals()->update(new UpdateDealInput($deal->id(), contactPersonIds: [$this->tomas->id()], clientPartyId: $this->beta->id()), ContactPersonDraft::new('Eva Example', 'eva@example.com', 'Buyer'));

        $eva = array_values(array_filter($this->directory()->personsOf($this->beta->id()), static fn (PartyPersonSummary $person): bool => 'Eva Example' === $person->displayName));
        self::assertCount(1, $eva);
        self::assertSame(['eva@example.com', 'Buyer'], [$eva[0]->email, $eva[0]->position]);
        self::assertSame([$this->tomas->id(), $eva[0]->id], $this->reads()->deal($deal->id())->contactPersonIds);
        self::assertSame([], array_filter($this->directory()->personsOf($this->client->id()), static fn (PartyPersonSummary $person): bool => 'Eva Example' === $person->displayName));

        // Without the list, the person joins the ones the deal has.
        $this->deals()->update(new UpdateDealInput($deal->id()), ContactPersonDraft::new('Ivo Example'));
        self::assertCount(3, $this->reads()->deal($deal->id())->contactPersonIds);
    }

    public function testARefusedPersonStoresNothing(): void
    {
        $parties = $this->partyCount();

        $this->expectRefusedPerson(['name' => ContactPersonRefused::REQUIRED, 'email' => ContactPersonRefused::EMAIL], fn (): Deal => $this->deals()->create(new CreateDealInput('Roof audit', $this->client->id()), ContactPersonDraft::new('', 'not-an-address')));
        self::assertSame([], $this->reads()->deals(), 'The deal is not created either.');

        $deal = $this->deal(contactPersonIds: [$this->petra->id()]);
        $this->expectRefusedPerson(['position' => ContactPersonRefused::TOO_LONG], fn (): Deal => $this->deals()->update(new UpdateDealInput($deal->id(), title: 'Renamed', clientPartyId: $this->beta->id()), ContactPersonDraft::new('Eva Example', position: str_repeat('a', 101))));
        $kept = $this->reload(Deal::class, $deal->id());
        self::assertSame([$this->client->id(), 'Roof audit'], [$kept->clientPartyId(), $kept->title()]);
        self::assertSame([$this->petra->id()], $this->reads()->deal($deal->id())->contactPersonIds);
        self::assertSame($parties, $this->partyCount());
    }

    /**
     * @param array<string, string> $reasons
     * @param callable(): mixed     $call
     */
    private function expectRefusedPerson(array $reasons, callable $call): void
    {
        try {
            $call();
        } catch (ContactPersonRefused $refused) {
            self::assertSame($reasons, $refused->reasons);

            return;
        }
        self::fail('Expected the person to be refused.');
    }

    private function loosePerson(string $name): Party
    {
        $person = new Party(PartyKind::Person, $name, null, null, '');
        $this->em->persist($person);
        $this->em->flush();

        return $person;
    }

    private function personOf(Party $company, Party $person): PartyPersonSummary
    {
        $found = array_values(array_filter($this->directory()->personsOf($company->id()), static fn (PartyPersonSummary $summary): bool => $summary->id === $person->id()));
        self::assertCount(1, $found);

        return $found[0];
    }

    private function link(Party $company, Party $person): ContactLink
    {
        $this->em->clear();
        $link = $this->em->getRepository(ContactLink::class)->findOneBy(['company' => $company->id(), 'person' => $person->id()]);
        self::assertInstanceOf(ContactLink::class, $link);

        return $link;
    }

    private function partyCount(): int
    {
        return $this->em->getRepository(Party::class)->count([]);
    }

    private function directory(): PartyDirectory
    {
        return self::getContainer()->get(PartyDirectory::class);
    }
}
