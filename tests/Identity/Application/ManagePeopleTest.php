<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\Identity\Application;

use Foresi\Audit\Application\AuditActor;
use Foresi\Identity\Application\ManagePeople;
use Foresi\Identity\Application\People;
use Foresi\Identity\Application\PeopleRefused;
use Foresi\Identity\Domain\Membership;
use Foresi\Identity\Domain\Role;
use Foresi\Identity\Domain\User;
use Foresi\Party\Application\PartyQuery;
use Foresi\Shared\Domain\Id;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;

/**
 * What the owner changes about the people, with the ports faked: the refusals,
 * the organisation that keeps an owner, and what the log says about a reset.
 */
final class ManagePeopleTest extends TestCase
{
    /** @var People&Stub */
    private People $people;

    /** @var PartyQuery&Stub */
    private PartyQuery $parties;

    protected function setUp(): void
    {
        $this->people = self::createStub(People::class);
        $this->parties = self::createStub(PartyQuery::class);
    }

    public function testAnUnknownContactIsRefused(): void
    {
        $this->people->method('membership')->willReturn($this->membership(Role::Member));
        $this->parties->method('find')->willReturn(null);
        $this->expectRefusal('contact');

        $this->service()->change(Id::generate(), Role::Member, Id::generate(), null);
    }

    public function testAnUnknownPersonIsRefused(): void
    {
        $this->people->method('membership')->willReturn(null);
        $this->expectRefusal('unknown');

        $this->service()->reactivate(Id::generate());
    }

    public function testTheLastActiveOwnerIsNotDeactivated(): void
    {
        $this->people->method('membership')->willReturn($this->membership(Role::Owner));
        $this->people->method('activeOwners')->willReturn(1);
        $this->expectRefusal('last_owner');

        $this->service()->deactivate(Id::generate());
    }

    public function testAnOwnerIsDeactivatedWhileAnotherRemains(): void
    {
        $membership = $this->membership(Role::Owner);
        $this->people->method('membership')->willReturn($membership);
        $this->people->method('activeOwners')->willReturn(2);

        $this->service()->deactivate(Id::generate());

        self::assertFalse($membership->isActive());
    }

    public function testAResetIsLoggedWithWhoseSignInAndByWhom(): void
    {
        $membership = $this->membership(Role::Member);
        $this->people->method('membership')->willReturn($membership);
        $logger = self::createMock(LoggerInterface::class);
        $logger->expects(self::once())->method('notice')->with(
            'Two-factor sign-in was reset by an owner.',
            ['user' => $membership->user()->id(), 'by' => 'owner-id'],
        );

        $this->service($logger)->resetTwoFactor(Id::generate());
    }

    private function membership(Role $role): Membership
    {
        return new Membership(new User('jana@example.com', 'hash', 'Jana Example'), $role);
    }

    private function expectRefusal(string $reason): void
    {
        $this->expectException(PeopleRefused::class);
        $this->expectExceptionMessageIsOrContains(sprintf('The change is refused: %s.', $reason));
    }

    private function service(?LoggerInterface $logger = null): ManagePeople
    {
        $actor = self::createStub(AuditActor::class);
        $actor->method('userId')->willReturn('owner-id');
        $clock = self::createStub(ClockInterface::class);
        $clock->method('now')->willReturn(new \DateTimeImmutable('2026-11-07 10:00:00'));

        return new ManagePeople($this->people, $this->parties, $actor, $clock, $logger ?? self::createStub(LoggerInterface::class));
    }
}
