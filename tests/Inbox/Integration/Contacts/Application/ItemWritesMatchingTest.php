<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\Inbox\Integration\Contacts\Application;

use Foresi\Inbox\Application\Api\CreateContactFromItemInput;
use Foresi\Inbox\Application\Api\LinkItemInput;
use Foresi\Inbox\Application\Api\MatchItemAgainInput;
use Foresi\Inbox\Application\Api\MatchItemInput;
use Foresi\Inbox\Domain\InboxItem;
use Foresi\Inbox\Domain\MatchBasis;
use Foresi\Party\Contract\PartyDirectory;
use Foresi\Party\Contract\PartySummary;
use Foresi\Shared\Domain\Id;
use Foresi\Tests\Inbox\Support\InboxMail;
use Foresi\Tests\Inbox\Support\InboxTestCase;

/**
 * Matching an item again and making a contact of its sender: whom the mail names as its sender
 * (the forwarder is not the sender of a forward), what the receiving server vouched for, and
 * what is left to do when Mail has removed the mail.
 */
final class ItemWritesMatchingTest extends InboxTestCase
{
    public function testAForwardIsMatchedAgainByItsOriginalSenderAndNeverByTheDomain(): void
    {
        $this->contact('company', 'Client A s.r.o.', 'office@client-a.example.com', '12345679');
        $verifiedOwner = ['Authentication-Results' => InboxMail::verifiedBy('example.com')];
        $itemId = $this->receive(InboxMail::plain($this->address, from: 'Owner Example <owner@example.com>', subject: 'Fwd: Offer', body: InboxMail::gmailForward('New Person <new.person@client-a.example.com>'), headers: $verifiedOwner));
        self::assertTrue($this->item($itemId)->isForwarded());

        $this->writes()->matchAgain(new MatchItemAgainInput($itemId));
        self::assertNull($this->item($itemId)->contactId(), 'An original sender nobody verified is not matched by its domain.');

        $person = $this->contact('person', 'New Person', 'new.person@client-a.example.com');
        $this->writes()->matchAgain(new MatchItemAgainInput($itemId));
        $matched = $this->item($itemId);
        self::assertSame([$person, MatchBasis::Address], [$matched->contactId(), $matched->matchBasis()]);
    }

    public function testAVerifiedSenderIsMatchedAgainByItsDomainAndAnUnverifiedOneIsNot(): void
    {
        $verified = $this->receive(InboxMail::plain($this->address, from: 'New Person <new.person@client-a.example.com>', messageId: 'v-1@client-a.example.com', headers: ['Authentication-Results' => InboxMail::verifiedBy('client-a.example.com')]));
        $unverified = $this->receive(InboxMail::plain($this->address, from: 'New Person <new.person@client-a.example.com>', messageId: 'v-2@client-a.example.com'));
        $company = $this->contact('company', 'Client A s.r.o.', 'office@client-a.example.com', '12345679');

        $this->writes()->matchAgain(new MatchItemAgainInput($verified));
        $this->writes()->matchAgain(new MatchItemAgainInput($unverified));

        self::assertSame([$company, MatchBasis::Domain], [$this->item($verified)->contactId(), $this->item($verified)->matchBasis()]);
        self::assertNull($this->item($unverified)->contactId());
    }

    public function testMatchingAgainAnItemWhoseMailIsGoneClearsTheMatch(): void
    {
        $person = $this->contact('person', 'Client A', self::CLIENT);
        $itemId = $this->receive(InboxMail::plain($this->address));
        $this->writes()->match(new MatchItemInput($itemId, $person));
        $this->em->getConnection()->executeStatement('DELETE FROM mail_message');

        $this->writes()->matchAgain(new MatchItemAgainInput($itemId));

        self::assertNull($this->item($itemId)->contactId());
    }

    public function testMatchingAgainAddsTheNewContactToTheEmailActivity(): void
    {
        $output = $this->contact('person', 'Client C', 'client.c@client-c.example.com');
        $itemId = $this->receive(InboxMail::plain($this->address));
        $this->writes()->link(new LinkItemInput($itemId, 'contact', $output));
        $person = $this->contact('person', 'Client A', self::CLIENT);

        $this->writes()->matchAgain(new MatchItemAgainInput($itemId));

        $activityId = $this->item($itemId)->emailActivityId();
        $linked = $this->em->getConnection()->fetchFirstColumn('SELECT target_id FROM activities_activity_link WHERE activity_id = ?', [$activityId]);
        self::assertEqualsCanonicalizing([$output, $person], $linked);
    }

    public function testAContactMadeFromAnItemGetsTheAddressTheInputGivesOverTheSenders(): void
    {
        $itemId = $this->receive(InboxMail::plain($this->address));

        $item = $this->writes()->createContact(new CreateContactFromItemInput($itemId, 'person', 'Client A', 'Example Street 2', 'Example City', '110 00', 'CZ', email: 'billing@client-a.example.com'));

        self::assertSame('billing@client-a.example.com', $this->contactEmail($item));
    }

    public function testAContactMadeFromAForwardGetsTheOriginalSendersAddress(): void
    {
        $itemId = $this->receive(InboxMail::plain($this->address, from: 'Owner Example <owner@example.com>', subject: 'Fwd: Offer', body: InboxMail::gmailForward('New Person <new.person@client-a.example.com>')));

        $item = $this->writes()->createContact(new CreateContactFromItemInput($itemId, 'person', 'New Person', 'Example Street 2', 'Example City', '110 00', 'CZ'));

        self::assertSame('new.person@client-a.example.com', $this->contactEmail($item));
    }

    public function testAContactMadeFromAnItemWhoseMailIsGoneHasNoAddress(): void
    {
        $itemId = $this->receive(InboxMail::plain($this->address));
        $this->em->getConnection()->executeStatement('DELETE FROM mail_message');

        $item = $this->writes()->createContact(new CreateContactFromItemInput($itemId, 'person', 'Client A', 'Example Street 2', 'Example City', '110 00', 'CZ'));

        self::assertNull($this->contactEmail($item));
    }

    public function testLinkingAnUnknownKindNamesTheKindsOffered(): void
    {
        $itemId = $this->receive(InboxMail::plain($this->address));

        try {
            $this->writes()->link(new LinkItemInput($itemId, 'spaceship', Id::generate()));
            self::fail('An unknown kind is refused.');
        } catch (\Throwable $refused) {
            self::assertStringContainsString('kinds offered are: contact', $refused->getMessage());
        }
    }

    private function contactEmail(InboxItem $item): ?string
    {
        $contact = self::getContainer()->get(PartyDirectory::class)->find((string) $item->contactId());
        self::assertInstanceOf(PartySummary::class, $contact);

        return $contact->email;
    }
}
