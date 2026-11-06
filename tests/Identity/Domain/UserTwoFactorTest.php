<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Tests\Identity\Domain;

use App\Identity\Domain\User;
use PHPUnit\Framework\TestCase;

final class UserTwoFactorTest extends TestCase
{
    public function testANewUserHasNoTwoFactorSignIn(): void
    {
        $user = $this->user();

        self::assertFalse($user->hasTwoFactor());
        self::assertNull($user->totpSecretSealed());
        self::assertSame(0, $user->recoveryCodesLeft());
        self::assertSame(0, $user->trustedDeviceVersion());
    }

    public function testEnrolKeepsEachRecoveryCodeOnceAndUsesItOnce(): void
    {
        $user = $this->user();
        $user->enrolTwoFactor('k1:sealed', ['a', 'b', 'a']);

        self::assertTrue($user->hasTwoFactor());
        self::assertSame('k1:sealed', $user->totpSecretSealed());
        self::assertSame(2, $user->recoveryCodesLeft());
        self::assertTrue($user->useRecoveryCode('b'));
        self::assertFalse($user->useRecoveryCode('b'));
        self::assertFalse($user->hasRecoveryCode('b'));
        self::assertTrue($user->hasRecoveryCode('a'));
        self::assertSame(1, $user->recoveryCodesLeft());
    }

    public function testResetForgetsEverythingAndTheTrustedBrowsers(): void
    {
        $user = $this->user();
        $user->enrolTwoFactor('k1:sealed', ['a']);
        $user->resetTwoFactor();
        $user->resetTwoFactor();

        self::assertFalse($user->hasTwoFactor());
        self::assertFalse($user->hasRecoveryCode('a'));
        self::assertSame(2, $user->trustedDeviceVersion());
    }

    public function testEnrolNeedsASecret(): void
    {
        $this->expectExceptionMessageIsOrContains('Two-factor sign-in needs a sealed secret.');
        $this->user()->enrolTwoFactor('', ['a']);
    }

    public function testEnrolNeedsRecoveryCodes(): void
    {
        $this->expectExceptionMessageIsOrContains('Two-factor sign-in needs recovery codes.');
        $this->user()->enrolTwoFactor('k1:sealed', []);
    }

    private function user(): User
    {
        return new User('user@example.com', 'hash', 'Example User');
    }
}
