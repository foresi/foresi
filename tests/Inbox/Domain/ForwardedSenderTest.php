<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\Inbox\Domain;

use Foresi\Inbox\Domain\ForwardedSender;
use PHPUnit\Framework\TestCase;

/**
 * The sender a forward names: kept lower case, with an address of up to 320 characters.
 */
final class ForwardedSenderTest extends TestCase
{
    public function testTheAddressIsTrimmedAndLowerCasedAlsoBeyondAscii(): void
    {
        self::assertSame('žlutá@example.com', new ForwardedSender("  ŽLUTÁ@Example.com\n")->address);
    }

    public function testAnAddressOfExactly320CharactersIsKeptAndOneMoreIsRefused(): void
    {
        $local = static fn (int $length): string => str_repeat('a', $length - \strlen('@example.com')).'@example.com';

        self::assertSame(320, \strlen(new ForwardedSender($local(320))->address));
        $this->expectException(\InvalidArgumentException::class);
        new ForwardedSender($local(321));
    }

    public function testTheLimitCountsCharactersNotBytes(): void
    {
        $address = str_repeat('ž', 320 - \strlen('@example.com')).'@example.com';

        self::assertSame(320, mb_strlen(new ForwardedSender($address)->address));
    }

    public function testAnAddressWithoutAnAtSignIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new ForwardedSender('client.a.example.com');
    }

    public function testAnEmptyAddressIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new ForwardedSender('  ');
    }
}
