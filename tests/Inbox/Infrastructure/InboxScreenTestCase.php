<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\Inbox\Infrastructure;

use Doctrine\ORM\EntityManagerInterface;
use Foresi\Activities\Domain\ActivityTiming;
use Foresi\Activities\Domain\ActivityType;
use Foresi\Identity\Domain\Role;
use Foresi\Identity\Domain\User;
use Foresi\Identity\Infrastructure\Security\SecurityUser;
use Foresi\Inbox\Application\InboxPurpose;
use Foresi\Inbox\Domain\InboxItem;
use Foresi\Inbox\Infrastructure\Doctrine\DoctrineItemStore;
use Foresi\Mail\Application\ReceiveMail;
use Foresi\Mail\Application\ReceiveOutcome;
use Foresi\Mail\Contract\AddressIssuer;
use Foresi\Mail\Domain\InboundChannel;
use Foresi\Tests\Identity\Support\IdentityFixtures;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Clock\Clock;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Clock\NativeClock;

/**
 * The inbox screens in a browser: the owner of an inbox address signed in, mail
 * delivered through Mail's real intake. Synthetic mail and example.com data only.
 */
abstract class InboxScreenTestCase extends WebTestCase
{
    protected KernelBrowser $client;
    protected EntityManagerInterface $em;
    protected User $owner;
    protected string $address;

    protected function setUp(): void
    {
        Clock::set(new MockClock('2026-11-25 10:00:00', 'UTC'));
        $this->client = self::createClient();
        $this->client->disableReboot();
        $this->em = self::getContainer()->get('doctrine.orm.entity_manager');
        $fixtures = new IdentityFixtures($this->em);
        $fixtures->rebuildSchema();
        $this->em->persist(new ActivityType('email', null, ActivityTiming::None, null, new \DateTimeImmutable('2026-11-25 10:00:00')));
        $this->owner = $fixtures->user('owner@example.com');
        $fixtures->join($this->owner, Role::Owner);
        $this->address = self::getContainer()->get(AddressIssuer::class)->issue(InboxPurpose::KEY, $this->owner->id(), 'My inbox')->address;
        $this->signInAs($this->owner);
    }

    protected function tearDown(): void
    {
        Clock::set(new NativeClock());
        parent::tearDown();
    }

    protected function signInAs(User $user): void
    {
        $this->client->loginUser(new SecurityUser($user->id(), $user->email(), $user->passwordHash()), 'main');
    }

    protected function colleague(): User
    {
        $fixtures = new IdentityFixtures($this->em);
        $user = $fixtures->user('colleague@example.com');
        $fixtures->join($user, Role::Member);

        return $user;
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
}
