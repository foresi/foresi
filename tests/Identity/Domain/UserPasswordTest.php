<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\Identity\Domain;

use Foresi\Identity\Domain\User;
use PHPUnit\Framework\TestCase;

final class UserPasswordTest extends TestCase
{
    public function testANewPasswordReplacesTheHashAndForgetsTheTrustedBrowsers(): void
    {
        $user = new User('testing@example.com', 'old-hash', 'Example Person');
        $user->enrolTwoFactor('sealed', ['code-hash']);

        $user->changePassword('new-hash');

        self::assertSame('new-hash', $user->passwordHash());
        self::assertSame(1, $user->trustedDeviceVersion());
        // Unlike a reset, the two-factor sign-in itself stays.
        self::assertTrue($user->hasTwoFactor());
        self::assertTrue($user->hasRecoveryCode('code-hash'));
    }

    public function testAnEmptyHashIsRefused(): void
    {
        $user = new User('testing@example.com', 'old-hash', 'Example Person');

        try {
            $user->changePassword('');
            self::fail('An empty hash was accepted.');
        } catch (\InvalidArgumentException) {
            self::assertSame('old-hash', $user->passwordHash());
            self::assertSame(0, $user->trustedDeviceVersion());
        }
    }

    public function testForgettingTheTrustedBrowsersKeepsEverythingElse(): void
    {
        $user = new User('testing@example.com', 'hash', 'Example Person');
        $user->enrolTwoFactor('sealed', ['code-hash']);

        $user->forgetTrustedDevices();
        $user->forgetTrustedDevices();

        self::assertSame(2, $user->trustedDeviceVersion());
        self::assertSame('sealed', $user->totpSecretSealed());
        self::assertSame('hash', $user->passwordHash());
    }
}
