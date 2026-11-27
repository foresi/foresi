<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\Inbox\Support;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Foresi\Activities\Domain\ActivityTiming;
use Foresi\Activities\Domain\ActivityType;
use Foresi\Contacts\Contract\ContactCreation;
use Foresi\Contacts\Contract\NewContact;
use Foresi\Events\Infrastructure\Dbal\EventLogTable;
use Foresi\Identity\Domain\Membership;
use Foresi\Identity\Domain\Role;
use Foresi\Identity\Domain\User;
use Foresi\Identity\Infrastructure\Security\SecurityUser;
use Foresi\Inbox\Application\InboxPurpose;
use Foresi\Inbox\Application\ItemWrites;
use Foresi\Inbox\Domain\InboxItem;
use Foresi\Inbox\Infrastructure\Doctrine\DoctrineItemStore;
use Foresi\Mail\Application\ReceiveMail;
use Foresi\Mail\Application\ReceiveOutcome;
use Foresi\Mail\Contract\AddressIssuer;
use Foresi\Mail\Domain\InboundChannel;
use Foresi\Party\Domain\Party;
use Foresi\Shared\Contract\Refused;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\Clock;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Clock\NativeClock;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;

/**
 * The inbox on a fresh database: the owner of an inbox address signed in, mail
 * delivered through Mail's real intake, real contacts and the seeded e-mail
 * activity type. Synthetic mail and example.com data only.
 */
abstract class InboxTestCase extends KernelTestCase
{
    use NeedsTheAddressBook;

    protected const string OWNER = 'owner@example.com';
    protected const string CLIENT = 'client.a@client-a.example.com';

    protected EntityManagerInterface $em;
    protected MockClock $clock;
    protected User $owner;
    protected string $address;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
        $tool = new SchemaTool($this->em);
        $metadata = $this->em->getMetadataFactory()->getAllMetadata();
        $tool->dropSchema($metadata);
        $tool->createSchema($metadata);
        $this->clock = new MockClock('2026-11-25 10:00:00', 'UTC');
        Clock::set($this->clock);

        // The type the activities migration seeds, which a schema built from the mapping lacks.
        $this->em->persist(new ActivityType('email', null, ActivityTiming::None, null, $this->clock->now()));
        $this->owner = $this->person(self::OWNER, Role::Owner);
        $this->address = self::getContainer()->get(AddressIssuer::class)->issue(InboxPurpose::KEY, $this->owner->id(), 'My inbox')->address;
        $this->signIn($this->owner);
    }

    protected function tearDown(): void
    {
        Clock::set(new NativeClock());
        parent::tearDown();
    }

    protected function person(string $email, Role $role): User
    {
        $user = new User($email, 'hash', 'Example Person');
        $this->em->persist($user);
        $this->em->persist(new Membership($user, $role));
        $this->em->flush();

        return $user;
    }

    protected function signIn(User $user): void
    {
        self::getContainer()->get(TokenStorageInterface::class)->setToken(new UsernamePasswordToken(new SecurityUser($user->id(), $user->email(), 'hash'), 'main', ['ROLE_USER']));
    }

    /**
     * Delivers the raw mail to the owner's inbox address and answers the id of its item.
     */
    protected function receive(string $raw): string
    {
        self::assertSame(ReceiveOutcome::Stored, self::getContainer()->get(ReceiveMail::class)->receive($raw, InboundChannel::Webhook, $this->address));
        $messageId = $this->em->getConnection()->fetchOne('SELECT id FROM mail_message WHERE raw_sha256 = ? ORDER BY received_at DESC, id DESC', [hash('sha256', $raw)]);
        self::assertIsString($messageId);
        $item = new DoctrineItemStore($this->em)->itemOfMessage($messageId);
        self::assertInstanceOf(InboxItem::class, $item);

        return $item->id();
    }

    protected function item(string $id): InboxItem
    {
        $this->em->clear();
        $item = new DoctrineItemStore($this->em)->item($id);
        self::assertInstanceOf(InboxItem::class, $item);

        return $item;
    }

    protected function contact(string $kind, string $name, string $email, string $registrationNumber = ''): string
    {
        self::needsTheAddressBook();

        return self::getContainer()->get(ContactCreation::class)->create(new NewContact($kind, $name, 'Example Street 2', 'Example City', '110 00', 'CZ', $registrationNumber, email: $email));
    }

    protected function archive(string $contactId): void
    {
        $party = $this->em->find(Party::class, $contactId);
        self::assertInstanceOf(Party::class, $party);
        $party->archive($this->clock->now());
        $this->em->flush();
    }

    protected function writes(): ItemWrites
    {
        return self::getContainer()->get(ItemWrites::class);
    }

    /**
     * @return list<array<mixed>> the decoded data of the events of the name, oldest first
     */
    protected function eventData(string $name): array
    {
        $rows = $this->em->getConnection()->fetchFirstColumn('SELECT '.EventLogTable::DATA.' FROM '.EventLogTable::LOG.' WHERE '.EventLogTable::NAME.' = ? ORDER BY '.EventLogTable::ID, [$name]);

        $events = [];
        foreach ($rows as $data) {
            self::assertIsString($data);
            $decoded = json_decode($data, true, flags: \JSON_THROW_ON_ERROR);
            self::assertIsArray($decoded);
            $events[] = $decoded;
        }

        return $events;
    }

    protected function assertRefused(string $reason, callable $work): void
    {
        try {
            $work();
        } catch (Refused $refused) {
            self::assertSame($reason, $refused->reason(), $refused->getMessage());

            return;
        }
        self::fail(\sprintf('Expected the refusal %s.', $reason));
    }
}
