<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\Identity\Application;

use Foresi\Identity\Application\AcceptInvitation;
use Foresi\Identity\Application\InvitationRefused;
use Foresi\Identity\Application\Invitations;
use Foresi\Identity\Application\MembershipDirectory;
use Foresi\Identity\Application\Organisation;
use Foresi\Identity\Application\People;
use Foresi\Identity\Application\UserRepository;
use Foresi\Identity\Domain\Invitation;
use Foresi\Identity\Domain\InvitationToken;
use Foresi\Identity\Domain\Role;
use Foresi\Identity\Domain\User;
use Foresi\Shared\Domain\Id;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Psr\Clock\ClockInterface;

/**
 * Following an invitation link, with the ports faked: the name a new person
 * gets, who may use the link while signed in, and the links that lead nowhere.
 */
final class AcceptInvitationTest extends TestCase
{
    private \DateTimeImmutable $now;
    private string $token;

    /** @var Invitations&Stub */
    private Invitations $invitations;

    /** @var UserRepository&Stub */
    private UserRepository $users;

    protected function setUp(): void
    {
        $this->now = new \DateTimeImmutable('2026-11-07 10:00:00');
        $this->token = InvitationToken::generate();
        $invitation = new Invitation('jana@example.com', 'Jana Example', Role::Member, null, Id::generate(), 'cs', InvitationToken::hash($this->token), $this->now);
        $this->invitations = self::createStub(Invitations::class);
        $this->invitations->method('byTokenHash')->willReturn($invitation);
        $this->users = self::createStub(UserRepository::class);
    }

    public function testANewPersonWhoGivesNoNameKeepsTheNameTheyWereInvitedBy(): void
    {
        $this->users->method('findByEmail')->willReturn(null);

        $user = $this->service()->asNewUser($this->token, "  \t ", 'hash');

        self::assertSame('Jana Example', $user->displayName());
        self::assertSame('cs', $user->locale());
    }

    public function testANewPersonWhoGivesANameIsKnownByIt(): void
    {
        $this->users->method('findByEmail')->willReturn(null);

        self::assertSame('Jana Nováková', $this->service()->asNewUser($this->token, ' Jana Nováková ', 'hash')->displayName());
    }

    public function testAnUnknownLinkIsRefused(): void
    {
        $this->expectRefusal('unknown');

        $this->service()->asNewUser('not a token', 'Jana', 'hash');
    }

    public function testAnAccountThatDoesNotExistCannotUseTheLink(): void
    {
        $this->users->method('findById')->willReturn(null);
        $this->expectRefusal('other_user');

        $this->service()->asUser($this->token, Id::generate());
    }

    public function testAnotherPersonCannotUseTheLink(): void
    {
        $this->users->method('findById')->willReturn(new User('someone@example.com', 'hash', 'Someone Else'));
        $this->expectRefusal('other_user');

        $this->service()->asUser($this->token, Id::generate());
    }

    private function expectRefusal(string $reason): void
    {
        $this->expectException(InvitationRefused::class);
        $this->expectExceptionMessageIsOrContains(sprintf('The invitation is refused: %s.', $reason));
    }

    private function service(): AcceptInvitation
    {
        $clock = self::createStub(ClockInterface::class);
        $clock->method('now')->willReturn($this->now);
        $memberships = self::createStub(MembershipDirectory::class);
        $memberships->method('organisationOf')->willReturn(new Organisation('Example Company', Role::Owner));

        return new AcceptInvitation($this->invitations, self::createStub(People::class), $this->users, $memberships, $clock);
    }
}
