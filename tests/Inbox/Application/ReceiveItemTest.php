<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\Inbox\Application;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Foresi\Audit\Application\AuditActor;
use Foresi\Contacts\Contract\ContactCreation;
use Foresi\Contacts\Contract\NewContact;
use Foresi\Events\Infrastructure\Dbal\EventLogTable;
use Foresi\Identity\Domain\Membership;
use Foresi\Identity\Domain\Role;
use Foresi\Identity\Domain\User;
use Foresi\Inbox\Application\InboxPurpose;
use Foresi\Inbox\Application\ReceiveItem;
use Foresi\Inbox\Domain\InboxItem;
use Foresi\Inbox\Domain\ItemState;
use Foresi\Inbox\Domain\MatchBasis;
use Foresi\Inbox\Infrastructure\Doctrine\DoctrineItemStore;
use Foresi\Inbox\Infrastructure\Events\InboxEventSubjectAccess;
use Foresi\Mail\Application\ReceiveMail;
use Foresi\Mail\Application\ReceiveOutcome;
use Foresi\Mail\Contract\AddressIssuer;
use Foresi\Mail\Domain\InboundChannel;
use Foresi\Shared\Contract\Event\EventSubject;
use Foresi\Tests\Inbox\Support\InboxMail;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Mail delivered through Mail's real intake to a person's inbox address becomes
 * an item of theirs, matched to the sender. Synthetic mail and example.com
 * contacts only; nobody is signed in, as for the webhook.
 */
final class ReceiveItemTest extends KernelTestCase
{
    private const string OWNER = 'owner@example.com';
    private const string CLIENT = 'client.a@client-a.example.com';

    private EntityManagerInterface $em;
    private User $owner;
    private string $address;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
        $tool = new SchemaTool($this->em);
        $metadata = $this->em->getMetadataFactory()->getAllMetadata();
        $tool->dropSchema($metadata);
        $tool->createSchema($metadata);

        $this->owner = $this->person(self::OWNER, Role::Owner);
        $this->address = $this->issuer()->issue(InboxPurpose::KEY, $this->owner->id(), 'My inbox')->address;
    }

    public function testAMailBecomesANewItemOfTheOwnerMatchedByTheSendersAddress(): void
    {
        $person = $this->contact('person', 'Client A', self::CLIENT);

        $messageId = $this->deliver(InboxMail::plain($this->address));
        $item = $this->itemOf($messageId);

        self::assertSame($this->owner->id(), $item->ownerUserId());
        self::assertSame(ItemState::New, $item->state());
        self::assertNull($item->stateChangedBy());
        self::assertEquals($item->receivedAt(), $item->stateChangedAt());
        self::assertSame($person, $item->contactId());
        self::assertNull($item->companyId());
        self::assertSame(MatchBasis::Address, $item->matchBasis());
        self::assertFalse($item->isForwarded());
        self::assertNull($item->originalFrom());
        self::assertSame(hash('sha256', 'offer-1@client-a.example.com'), $item->threadKey());
        self::assertNull($item->duplicateOfPurpose());
        self::assertNull($item->emailActivityId());
        self::assertFalse($item->isTrashed());
    }

    public function testAnUnknownSenderLeavesTheItemUnmatched(): void
    {
        $item = $this->itemOf($this->deliver(InboxMail::plain($this->address, from: 'Someone <someone@unknown.example.com>')));

        self::assertNull($item->contactId());
        self::assertNull($item->matchBasis());
    }

    public function testAVerifiedSenderMatchesTheOneCompanyOfItsDomain(): void
    {
        $company = $this->contact('company', 'Client B s.r.o.', 'office@client-b.example.com', '12345679');

        $verified = $this->itemOf($this->deliver(InboxMail::plain($this->address, from: 'New Person <new.person@client-b.example.com>', messageId: 'b-1@client-b.example.com', headers: ['Authentication-Results' => InboxMail::verifiedBy('client-b.example.com')])));
        $unverified = $this->itemOf($this->deliver(InboxMail::plain($this->address, from: 'New Person <new.person@client-b.example.com>', messageId: 'b-2@client-b.example.com')));

        self::assertSame($company, $verified->contactId());
        self::assertSame(MatchBasis::Domain, $verified->matchBasis());
        self::assertNull($unverified->contactId(), 'An unverified sender is never matched by its domain.');
    }

    public function testAForwardedItemSaysTheOriginalSenderIsNotVerified(): void
    {
        $person = $this->contact('person', 'Client A', self::CLIENT);
        $this->contact('company', 'Client B s.r.o.', 'office@client-b.example.com', '12345679');
        // The owner's own provider vouches for the forward, which says nothing of whom it quotes.
        $verifiedOwner = ['Authentication-Results' => InboxMail::verifiedBy('example.com')];

        $forward = $this->itemOf($this->deliver(InboxMail::plain($this->address, from: 'Owner Example <Owner@Example.com>', subject: 'Fwd: Offer for the new office', messageId: 'fwd-1@example.com', body: InboxMail::gmailForward(), headers: $verifiedOwner)));
        $byDomain = $this->itemOf($this->deliver(InboxMail::plain($this->address, from: 'Owner Example <owner@example.com>', subject: 'Fwd: Hello', messageId: 'fwd-2@example.com', body: InboxMail::gmailForward('New Person <new.person@client-b.example.com>'), headers: $verifiedOwner)));

        self::assertTrue($forward->isForwarded());
        self::assertSame(self::CLIENT, $forward->originalFrom());
        self::assertSame($person, $forward->contactId(), 'Matched by the original sender, not the owner.');
        self::assertSame(MatchBasis::Address, $forward->matchBasis());
        self::assertTrue($byDomain->isForwarded());
        self::assertSame('new.person@client-b.example.com', $byDomain->originalFrom());
        self::assertNull($byDomain->contactId(), 'An original sender nobody verified is never matched by its domain.');
    }

    public function testAForwardTheParserDoesNotKnowKeepsTheForwarder(): void
    {
        $owner = $this->contact('person', 'Owner Example', self::OWNER);

        $item = $this->itemOf($this->deliver(InboxMail::plain($this->address, from: 'Owner Example <owner@example.com>', body: "Weitergeleitete Nachricht:\r\nVon: Client A <".self::CLIENT.">\r\n")));

        self::assertFalse($item->isForwarded());
        self::assertNull($item->originalFrom());
        self::assertSame($owner, $item->contactId());
    }

    public function testAForwardBlockFromSomeoneElseIsNoForward(): void
    {
        $item = $this->itemOf($this->deliver(InboxMail::plain($this->address, from: 'Colleague B <colleague.b@example.com>', body: InboxMail::gmailForward())));

        self::assertFalse($item->isForwarded());
        self::assertNull($item->originalFrom());
    }

    public function testThreadRepliesShareOneThreadKey(): void
    {
        $original = $this->itemOf($this->deliver(InboxMail::plain($this->address, messageId: 'root@client-a.example.com')));
        $reply = $this->itemOf($this->deliver(InboxMail::plain($this->address, subject: 'Re: Offer for the new office', messageId: 'reply-1@client-a.example.com', inReplyTo: 'root@client-a.example.com', references: ['root@client-a.example.com'])));
        $later = $this->itemOf($this->deliver(InboxMail::plain($this->address, subject: 'Re: Re: Offer for the new office', messageId: 'reply-2@client-a.example.com', inReplyTo: 'reply-1@example.com', references: ['root@client-a.example.com', 'reply-1@example.com'])));
        $other = $this->itemOf($this->deliver(InboxMail::plain($this->address, subject: 'Something else', messageId: 'other@client-a.example.com')));

        self::assertSame($original->threadKey(), $reply->threadKey());
        self::assertSame($original->threadKey(), $later->threadKey());
        self::assertNotSame($original->threadKey(), $other->threadKey());
    }

    public function testReceivingTwiceGivesOneItem(): void
    {
        $raw = InboxMail::plain($this->address);
        $messageId = $this->deliver($raw);

        self::assertSame(ReceiveOutcome::Duplicate, $this->receiveMail()->receive($raw, InboundChannel::Webhook, $this->address));
        $again = self::getContainer()->get(ReceiveItem::class)->receive($messageId);

        self::assertSame($this->itemOf($messageId)->id(), $again);
        self::assertSame(1, $this->rows('SELECT COUNT(*) FROM inbox_item'));
        self::assertCount(1, $this->events());
    }

    public function testTheReceivedEventCarriesTheItemIdOnly(): void
    {
        $item = $this->itemOf($this->deliver(InboxMail::plain($this->address)));

        $events = $this->events();

        self::assertCount(1, $events);
        $data = $events[0][EventLogTable::DATA];
        self::assertIsString($data);
        self::assertSame(['item_id' => $item->id()], json_decode($data, true, flags: \JSON_THROW_ON_ERROR));
        self::assertSame('inbox_item', $events[0][EventLogTable::SUBJECT_TYPE]);
        self::assertSame($item->id(), $events[0][EventLogTable::SUBJECT_ID]);
        self::assertEquals(1, $events[0][EventLogTable::VERSION]);
    }

    public function testOnlyTheOwnerSeesTheEventsOfAnItem(): void
    {
        $item = $this->itemOf($this->deliver(InboxMail::plain($this->address)));
        $colleague = $this->person('colleague@example.com', Role::Member);
        $access = new InboxEventSubjectAccess(new DoctrineItemStore($this->em), self::getContainer()->get(AuditActor::class));

        self::assertTrue($access->userSees($this->owner->id(), new EventSubject('inbox_item', $item->id())));
        self::assertFalse($access->userSees($colleague->id(), new EventSubject('inbox_item', $item->id())));
        self::assertFalse($access->userSees($this->owner->id(), new EventSubject('inbox_item', '0192f1a4-0000-7000-8000-000000000000')), 'A gone item is seen by no one.');
        self::assertFalse($access->userSees($this->owner->id(), new EventSubject('mail_message', $item->mailMessageId())));
        self::assertFalse($access->sees(new EventSubject('inbox_item', $item->id())), 'Nobody signed in sees nothing.');
    }

    public function testMailForAnOwnerWhoLeftIsDropped(): void
    {
        $leaver = $this->person('leaver@example.com', Role::Member);
        $address = $this->issuer()->issue(InboxPurpose::KEY, $leaver->id(), 'Inbox')->address;
        $this->em->getConnection()->executeStatement('DELETE FROM membership WHERE user_id = ?', [$leaver->id()]);

        self::assertSame(ReceiveOutcome::UnknownRecipient, $this->receiveMail()->receive(InboxMail::plain($address), InboundChannel::Webhook, $address));
        self::assertSame(0, $this->rows('SELECT COUNT(*) FROM inbox_item'));
        self::assertSame(0, $this->rows('SELECT COUNT(*) FROM mail_message'));
    }

    private function person(string $email, Role $role): User
    {
        $user = new User($email, 'hash', 'Example Person');
        $this->em->persist($user);
        $this->em->persist(new Membership($user, $role));
        $this->em->flush();

        return $user;
    }

    private function contact(string $kind, string $name, string $email, string $registrationNumber = ''): string
    {
        return self::getContainer()->get(ContactCreation::class)->create(new NewContact($kind, $name, 'Example Street 2', 'Example City', '110 00', 'CZ', $registrationNumber, email: $email));
    }

    /**
     * Delivers the raw mail as the webhook does, to the inbox address unless another is named.
     *
     * @return string the id of the stored mail message
     */
    private function deliver(string $raw, ?string $to = null): string
    {
        $to ??= $this->address;
        self::assertSame(ReceiveOutcome::Stored, $this->receiveMail()->receive($raw, InboundChannel::Webhook, $to));
        $id = $this->em->getConnection()->fetchOne('SELECT id FROM mail_message WHERE raw_sha256 = ? ORDER BY received_at DESC, id DESC', [hash('sha256', $raw)]);
        self::assertIsString($id);

        return $id;
    }

    private function itemOf(string $messageId): InboxItem
    {
        $this->em->clear();
        $item = new DoctrineItemStore($this->em)->itemOfMessage($messageId);
        self::assertInstanceOf(InboxItem::class, $item);

        return $item;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function events(): array
    {
        /* @var list<array<string, mixed>> */
        return $this->em->getConnection()->fetchAllAssociative('SELECT * FROM '.EventLogTable::LOG.' WHERE '.EventLogTable::NAME.' = ? ORDER BY '.EventLogTable::ID, ['inbox.item.received']);
    }

    private function rows(string $sql): int
    {
        $count = $this->em->getConnection()->fetchOne($sql);

        return is_numeric($count) ? (int) $count : -1;
    }

    private function issuer(): AddressIssuer
    {
        return self::getContainer()->get(AddressIssuer::class);
    }

    private function receiveMail(): ReceiveMail
    {
        return self::getContainer()->get(ReceiveMail::class);
    }
}
