<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\Mail\Infrastructure;

use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Foresi\Identity\Domain\Role;
use Foresi\Identity\Domain\User;
use Foresi\Identity\Infrastructure\Security\SecurityUser;
use Foresi\Mail\Application\ReceiveMail;
use Foresi\Mail\Application\ReceiveOutcome;
use Foresi\Mail\Contract\AddressIssuer;
use Foresi\Mail\Domain\InboundChannel;
use Foresi\Mail\Infrastructure\Events\MailEventAccess;
use Foresi\Mail\Infrastructure\Events\MailEventSubjectAccess;
use Foresi\Shared\Domain\Event\EventSubject;
use Foresi\Tests\Banking\Support\Scalars;
use Foresi\Tests\Identity\Support\IdentityFixtures;
use Foresi\Tests\Support\Mail\MailKit;
use Foresi\Tests\Support\Mail\PartialAddressIndex;
use Foresi\Tests\Support\Mail\RecordingPurpose;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;

/**
 * Who sees the arrival of a mail: the staff read the events of Mail, but the
 * arrival of a person's own mail only that person sees, in the feed and in their
 * webhooks; an address nobody owns shows to every staff reader.
 */
final class MailEventAccessTest extends KernelTestCase
{
    private User $owner;
    private User $colleague;
    private string $ownedMessage;
    private string $sharedMessage;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = self::getContainer();
        $em = $container->get(EntityManagerInterface::class);
        $fixtures = new IdentityFixtures($em);
        $fixtures->rebuildSchema();
        PartialAddressIndex::apply($container->get(Connection::class));
        RecordingPurpose::reset();
        $this->owner = $fixtures->user();
        $fixtures->join($this->owner, Role::Member);
        $this->colleague = $fixtures->user('colleague@example.com');
        $fixtures->join($this->colleague, Role::Member);

        $issuer = $container->get(AddressIssuer::class);
        $owned = $issuer->issue(RecordingPurpose::OWNED_KEY, $this->owner->id(), 'Mine')->address;
        $shared = $issuer->issue(RecordingPurpose::KEY, null, 'Feed')->address;
        $this->ownedMessage = $this->receive($owned, 'owned@mail.example.com');
        $this->sharedMessage = $this->receive($shared, 'shared@mail.example.com');
    }

    protected function tearDown(): void
    {
        RecordingPurpose::reset();
        parent::tearDown();
    }

    public function testOnlyTheOwnerSeesTheArrivalOfTheirMail(): void
    {
        $subject = new EventSubject('mail_message', $this->ownedMessage);
        $access = $this->subjectAccess();

        $this->signIn($this->owner);
        self::assertTrue($access->sees($subject));
        $this->signIn($this->colleague);
        self::assertFalse($access->sees($subject));

        self::assertTrue($access->userSees($this->owner->id(), $subject));
        self::assertFalse($access->userSees($this->colleague->id(), $subject));
    }

    public function testAnAddressWithoutAnOwnerShowsToEveryStaffReader(): void
    {
        $subject = new EventSubject('mail_message', $this->sharedMessage);
        $access = $this->subjectAccess();

        $this->signIn($this->colleague);
        self::assertTrue($access->sees($subject));
        self::assertTrue($access->userSees($this->owner->id(), $subject));
        self::assertTrue($this->readAccess()->mayRead());
        self::assertTrue($this->readAccess()->mayUserRead($this->colleague->id()));
    }

    public function testAnUnknownOrPurgedMessageIsSeenByNoOne(): void
    {
        $access = $this->subjectAccess();
        $this->signIn($this->owner);

        self::assertFalse($access->sees(new EventSubject('mail_message', '00000000-0000-4000-8000-000000000000')));
        self::assertFalse($access->userSees($this->owner->id(), new EventSubject('mail_message', '00000000-0000-4000-8000-000000000000')));
        self::assertFalse($access->sees(new EventSubject('other', $this->sharedMessage)));
    }

    private function receive(string $address, string $messageId): string
    {
        $container = self::getContainer();
        self::assertSame(ReceiveOutcome::Stored, $container->get(ReceiveMail::class)->receive(MailKit::mail($address, $messageId), InboundChannel::Webhook));

        return Scalars::text($container->get(Connection::class)->fetchOne('SELECT id FROM mail_message WHERE message_id_header = :id', ['id' => $messageId]));
    }

    private function subjectAccess(): MailEventSubjectAccess
    {
        return self::getContainer()->get(MailEventSubjectAccess::class);
    }

    private function readAccess(): MailEventAccess
    {
        return self::getContainer()->get(MailEventAccess::class);
    }

    private function signIn(User $user): void
    {
        self::getContainer()->get(TokenStorageInterface::class)->setToken(new UsernamePasswordToken(SecurityUser::fromUser($user), 'main', ['ROLE_USER']));
    }
}
