<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Tests\Identity\Infrastructure\Security;

use App\Identity\Infrastructure\Security\SecurityUser;
use App\Shared\Domain\Id;
use PHPUnit\Framework\TestCase;

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
}
