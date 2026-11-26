<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\Identity\Infrastructure\Security;

use Foresi\Identity\Infrastructure\Security\SecurityUser;
use Foresi\Shared\Domain\Id;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Security\Core\User\UserInterface;

final class SecurityUserTest extends TestCase
{
    public function testASessionKeepsTheUserButNotTheHash(): void
    {
        $id = Id::generate();

        $restored = unserialize(serialize(new SecurityUser($id, 'user@example.com', 'hash')));

        self::assertInstanceOf(SecurityUser::class, $restored);
        self::assertSame($id, $restored->id());
        self::assertSame('user@example.com', $restored->getUserIdentifier());
        self::assertSame(hash('crc32c', 'hash'), $restored->getPassword());
    }

    public function testASessionFromBeforeUuidsStillLoads(): void
    {
        $body = 'O:%d:"%s":3:{s:2:"id";i:12;s:5:"email";s:16:"user@example.com";s:12:"passwordHash";s:8:"12345678";}';

        $restored = unserialize(\sprintf($body, \strlen(SecurityUser::class), SecurityUser::class));

        self::assertInstanceOf(SecurityUser::class, $restored);
        self::assertSame('user@example.com', $restored->getUserIdentifier());
    }

    public function testASessionMatchesTheUserLoadedLaterUntilItsAddressPasswordOrVersionChanges(): void
    {
        $id = Id::generate();
        $session = unserialize(serialize(new SecurityUser($id, 'user@example.com', 'hash', sessionVersion: 1)));
        self::assertInstanceOf(SecurityUser::class, $session);

        self::assertTrue($session->isEqualTo(new SecurityUser($id, 'user@example.com', 'hash', 'secret', 3, 1)));
        self::assertTrue(new SecurityUser($id, 'user@example.com', 'hash')->isEqualTo(new SecurityUser($id, 'user@example.com', 'hash')));
        self::assertFalse($session->isEqualTo(new SecurityUser($id, 'other@example.com', 'hash', sessionVersion: 1)));
        self::assertFalse($session->isEqualTo(new SecurityUser($id, 'user@example.com', 'new hash', sessionVersion: 1)));
        // Changed to another address and back: the version tells the old session apart.
        self::assertFalse($session->isEqualTo(new SecurityUser($id, 'user@example.com', 'hash', sessionVersion: 2)));
        self::assertFalse($session->isEqualTo(self::createStub(UserInterface::class)));
    }

    public function testASessionFromBeforeSessionVersionsBeganWithVersionZero(): void
    {
        $body = 'O:%d:"%s":3:{s:2:"id";s:1:"a";s:5:"email";s:16:"user@example.com";s:12:"passwordHash";s:8:"%s";}';

        $restored = unserialize(\sprintf($body, \strlen(SecurityUser::class), SecurityUser::class, hash('crc32c', 'hash')));

        self::assertInstanceOf(SecurityUser::class, $restored);
        self::assertTrue($restored->isEqualTo(new SecurityUser('a', 'user@example.com', 'hash')));
        self::assertFalse($restored->isEqualTo(new SecurityUser('a', 'user@example.com', 'hash', sessionVersion: 1)));
    }

    public function testASessionWithAnUnreadableVersionDoesNotLoad(): void
    {
        $body = 'O:%d:"%s":4:{s:2:"id";s:1:"a";s:5:"email";s:16:"user@example.com";s:12:"passwordHash";s:4:"hash";s:14:"sessionVersion";s:1:"1";}';

        $this->expectException(\UnexpectedValueException::class);
        unserialize(\sprintf($body, \strlen(SecurityUser::class), SecurityUser::class));
    }

    public function testTheVersionSurvivesAnEnrolmentCheck(): void
    {
        $user = new SecurityUser('a', 'user@example.com', 'hash', sessionVersion: 4);

        self::assertTrue($user->withTotpSecret('secret')->isEqualTo($user));
    }
}
