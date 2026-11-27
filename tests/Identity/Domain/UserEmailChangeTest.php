<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\Identity\Domain;

use Foresi\Identity\Domain\EmailChangeToken;
use Foresi\Identity\Domain\User;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class UserEmailChangeTest extends TestCase
{
    private const string HASH = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';

    public function testARequestWaitsWithTheNormalisedAddressUntilItExpires(): void
    {
        $user = $this->user();
        $expires = new \DateTimeImmutable('2026-11-27 10:00:00');

        $user->requestEmailChange(' New@Example.com ', self::HASH, $expires);

        self::assertSame('testing@example.com', $user->email());
        self::assertSame('new@example.com', $user->pendingEmail(new \DateTimeImmutable('2026-11-27 09:59:59')));
        self::assertNull($user->pendingEmail($expires));
        self::assertSame($expires, $user->pendingEmailExpiresAt());
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function refusedRequests(): iterable
    {
        yield 'the current address' => [' Testing@EXAMPLE.com', self::HASH];
        yield 'not an address' => ['not-an-address', self::HASH];
        yield 'no link' => ['new@example.com', ''];
    }

    #[DataProvider('refusedRequests')]
    public function testARequestNeedsAnotherValidAddressAndALink(string $email, string $hash): void
    {
        $user = $this->user();

        $this->expectException(\InvalidArgumentException::class);
        $user->requestEmailChange($email, $hash, new \DateTimeImmutable('2026-11-27 10:00:00'));
    }

    public function testCancelForgetsTheRequest(): void
    {
        $user = $this->user();
        $user->requestEmailChange('new@example.com', self::HASH, new \DateTimeImmutable('2026-11-27 10:00:00'));

        $user->cancelEmailChange();

        self::assertNull($user->pendingEmail(new \DateTimeImmutable('2026-11-26 10:00:00')));
        self::assertNull($user->pendingEmailExpiresAt());
    }

    public function testConfirmationSwitchesTheAddressAndEndsTheSessions(): void
    {
        $user = $this->user();
        $user->requestEmailChange('new@example.com', self::HASH, new \DateTimeImmutable('2026-11-27 10:00:00'));

        $old = $user->confirmEmailChange(new \DateTimeImmutable('2026-11-26 10:00:00'));

        self::assertSame('testing@example.com', $old);
        self::assertSame('new@example.com', $user->email());
        self::assertSame(1, $user->sessionVersion());
        self::assertNull($user->pendingEmail(new \DateTimeImmutable('2026-11-26 10:00:00')));
        self::assertNull($user->pendingEmailExpiresAt());
    }

    public function testAnExpiredOrMissingRequestCannotBeConfirmed(): void
    {
        $user = $this->user();
        $user->requestEmailChange('new@example.com', self::HASH, new \DateTimeImmutable('2026-11-27 10:00:00'));

        try {
            $user->confirmEmailChange(new \DateTimeImmutable('2026-11-27 10:00:00'));
            self::fail('An expired change was confirmed.');
        } catch (\LogicException) {
            self::assertSame('testing@example.com', $user->email());
            self::assertSame(0, $user->sessionVersion());
        }
        $user->cancelEmailChange();
        $this->expectException(\LogicException::class);
        $user->confirmEmailChange(new \DateTimeImmutable('2026-11-26 10:00:00'));
    }

    public function testEndSessionsRaisesTheVersion(): void
    {
        $user = $this->user();

        $user->endSessions();
        $user->endSessions();

        self::assertSame(2, $user->sessionVersion());
    }

    public function testTheLinkTokenIsWellFormedAndKeptAsItsHash(): void
    {
        $token = EmailChangeToken::generate();

        self::assertTrue(EmailChangeToken::isWellFormed($token));
        self::assertFalse(EmailChangeToken::isWellFormed($token.'x'));
        self::assertSame(hash('sha256', $token), EmailChangeToken::hash($token));
    }

    private function user(): User
    {
        return new User('testing@example.com', 'hash', 'Example Person');
    }
}
