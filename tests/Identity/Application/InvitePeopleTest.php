<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\Identity\Application;

use Foresi\Audit\Application\AuditActor;
use Foresi\Identity\Application\InvitationMailer;
use Foresi\Identity\Application\InvitationRefused;
use Foresi\Identity\Application\Invitations;
use Foresi\Identity\Application\InvitePeople;
use Foresi\Identity\Application\MembershipDirectory;
use Foresi\Identity\Application\Organisation;
use Foresi\Identity\Application\People;
use Foresi\Identity\Application\UserRepository;
use Foresi\Identity\Domain\Invitation;
use Foresi\Identity\Domain\InvitationToken;
use Foresi\Identity\Domain\Role;
use Foresi\Identity\Domain\User;
use Foresi\Party\Application\PartyQuery;
use Foresi\Shared\Application\SystemMailUnavailable;
use Foresi\Shared\Domain\Id;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Psr\Clock\ClockInterface;

/**
 * Inviting, sending again and revoking, with the ports faked: what is refused
 * and why, what the mail says about who invites, and the link handed over when
 * the installation cannot send mail.
 */
final class InvitePeopleTest extends TestCase
{
    private \DateTimeImmutable $now;

    /** @var Invitations&Stub */
    private Invitations $invitations;

    /** @var UserRepository&Stub */
    private UserRepository $users;

    /** @var PartyQuery&Stub */
    private PartyQuery $parties;

    /** @var InvitationMailer&Stub */
    private InvitationMailer $mailer;

    /** @var MembershipDirectory&Stub */
    private MembershipDirectory $memberships;

    private ?string $actorId = 'inviter';

    /** @var list<string> */
    private array $lookedUp = [];

    protected function setUp(): void
    {
        $this->now = new \DateTimeImmutable('2026-11-07 10:00:00');
        $this->invitations = self::createStub(Invitations::class);
        $this->users = self::createStub(UserRepository::class);
        $this->parties = self::createStub(PartyQuery::class);
        $this->mailer = self::createStub(InvitationMailer::class);
        $this->mailer->method('link')->willReturnCallback(static fn (string $token): string => 'https://example.com/invitation/'.$token);
        $this->memberships = self::createStub(MembershipDirectory::class);
        $this->memberships->method('organisationOf')->willReturn(new Organisation('Example Company', Role::Owner));
    }

    public function testTheAddressIsTrimmedAndLowercasedBeforeItIsLookedUp(): void
    {
        $this->users->method('findByEmail')->willReturnCallback(function (string $email): null {
            $this->lookedUp[] = 'user:'.$email;

            return null;
        });
        $this->invitations->method('pendingFor')->willReturnCallback(function (string $email): array {
            $this->lookedUp[] = 'pending:'.$email;

            return [];
        });
        $this->mailer->method('isConfigured')->willReturn(false);

        $issued = $this->service()->invite("  Jana@Example.COM \n", 'Jana Example', Role::Member, null, 'en');

        self::assertSame(['user:jana@example.com', 'pending:jana@example.com'], $this->lookedUp);
        self::assertSame('jana@example.com', $issued->invitation->email());
    }

    public function testABlankNameIsRefusedAsTheName(): void
    {
        $this->expectRefusal('name');

        $this->service()->invite('jana@example.com', "  \t ", Role::Member, null, 'en');
    }

    public function testAnUnknownContactIsRefused(): void
    {
        $this->parties->method('find')->willReturn(null);
        $this->expectRefusal('contact');

        $this->service()->invite('jana@example.com', 'Jana Example', Role::Member, Id::generate(), 'en');
    }

    public function testAGuestIsInvitedWithoutAContact(): void
    {
        $this->users->method('findByEmail')->willReturn(null);
        $this->invitations->method('pendingFor')->willReturn([]);

        $issued = $this->service()->invite('alex@example.com', 'Alex Example', Role::Guest, null, 'en');

        self::assertSame(Role::Guest, $issued->invitation->role());
        self::assertNull($issued->invitation->party());
    }

    public function testOnlyASignedInOwnerInvites(): void
    {
        $this->actorId = null;
        $this->mailer->method('isConfigured')->willReturn(false);

        $this->expectException(\LogicException::class);

        $this->service()->invite('jana@example.com', 'Jana Example', Role::Member, null, 'en');
    }

    public function testSendingAnUnknownInvitationAgainIsRefused(): void
    {
        $this->invitations->method('find')->willReturn(null);
        $this->expectRefusal('unknown');

        $this->service()->resend(Id::generate());
    }

    public function testRevokingAnUnknownInvitationIsRefused(): void
    {
        $this->invitations->method('find')->willReturn(null);
        $this->expectRefusal('unknown');

        $this->service()->revoke(Id::generate());
    }

    public function testWithoutSystemMailTheLinkIsHandedOverAndNothingIsSent(): void
    {
        $this->users->method('findByEmail')->willReturn(null);
        $this->invitations->method('pendingFor')->willReturn([]);
        $mailer = $this->expectingMailer(false);
        $mailer->expects(self::never())->method('send');

        $issued = $this->service()->invite('jana@example.com', 'Jana Example', Role::Member, null, 'en');

        self::assertFalse($issued->mailed);
        self::assertStringStartsWith('https://example.com/invitation/', $issued->link);
    }

    public function testTheMailNamesWhoInvitesAndTheOrganisation(): void
    {
        $this->users->method('findByEmail')->willReturn(null);
        $this->users->method('findById')->willReturn(new User('owner@example.com', 'hash', 'Olga Owner'));
        $this->invitations->method('pendingFor')->willReturn([]);
        $this->expectingMailer(true)->expects(self::once())->method('send')->with(self::isInstanceOf(Invitation::class), self::isString(), 'Olga Owner', 'Example Company');

        $issued = $this->service()->invite('jana@example.com', 'Jana Example', Role::Member, null, 'en');

        self::assertTrue($issued->mailed);
    }

    public function testTheMailIsSentWithoutANameWhenTheInviterIsGone(): void
    {
        $this->users->method('findByEmail')->willReturn(null);
        $this->users->method('findById')->willReturn(null);
        $this->invitations->method('pendingFor')->willReturn([]);
        $this->expectingMailer(true)->expects(self::once())->method('send')->with(self::isInstanceOf(Invitation::class), self::isString(), '', 'Example Company');

        $this->service()->invite('jana@example.com', 'Jana Example', Role::Member, null, 'en');
    }

    public function testAMailThatCannotBeSentLeavesTheLinkToTheOwner(): void
    {
        $this->users->method('findByEmail')->willReturn(null);
        $this->invitations->method('pendingFor')->willReturn([]);
        $this->mailer->method('isConfigured')->willReturn(true);
        $this->mailer->method('send')->willThrowException(new SystemMailUnavailable());

        self::assertFalse($this->service()->invite('jana@example.com', 'Jana Example', Role::Member, null, 'en')->mailed);
    }

    public function testAnInvitationThatIsNotPendingIsNotSentAgain(): void
    {
        $invitation = new Invitation('jana@example.com', 'Jana Example', Role::Member, null, 'inviter', 'en', InvitationToken::hash('token'), $this->now);
        $invitation->revoke($this->now);
        $this->invitations->method('find')->willReturn($invitation);
        $this->expectRefusal('not_pending');

        $this->service()->resend($invitation->id());
    }

    private function expectingMailer(bool $configured): MockObject
    {
        $mailer = self::createMock(InvitationMailer::class);
        $mailer->method('link')->willReturnCallback(static fn (string $token): string => 'https://example.com/invitation/'.$token);
        $mailer->method('isConfigured')->willReturn($configured);
        $this->mailer = $mailer;

        return $mailer;
    }

    private function expectRefusal(string $reason): void
    {
        $this->expectException(InvitationRefused::class);
        $this->expectExceptionMessageIsOrContains(sprintf('The invitation is refused: %s.', $reason));
    }

    private function service(): InvitePeople
    {
        $actor = self::createStub(AuditActor::class);
        $actor->method('userId')->willReturn($this->actorId);
        $clock = self::createStub(ClockInterface::class);
        $clock->method('now')->willReturn($this->now);

        return new InvitePeople($this->invitations, self::createStub(People::class), $this->users, $this->parties, $this->mailer, $this->memberships, $actor, $clock);
    }
}
