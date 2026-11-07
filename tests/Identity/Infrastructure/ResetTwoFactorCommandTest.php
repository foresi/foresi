<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Tests\Identity\Infrastructure;

use App\Identity\Application\UserRepository;
use App\Tenancy\Application\TenantContext;
use App\Tests\Identity\Support\IdentityFixtures;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

final class ResetTwoFactorCommandTest extends KernelTestCase
{
    private CommandTester $tester;

    protected function setUp(): void
    {
        $kernel = self::bootKernel();
        $fixtures = new IdentityFixtures(self::getContainer()->get('doctrine.orm.entity_manager'), self::getContainer()->get(TenantContext::class));
        $fixtures->rebuildSchema();
        $user = $fixtures->user();
        $user->enrolTwoFactor('test1:sealed', [hash('sha256', 'CODE')]);
        $this->users()->save($user);
        $this->tester = new CommandTester(new Application($kernel)->find('app:user:reset-2fa'));
    }

    public function testResetsTheTwoFactorSignInAndForgetsTrustedBrowsers(): void
    {
        $status = $this->tester->execute(['email' => ' Testing@Example.com ']);

        self::assertSame(Command::SUCCESS, $status);
        self::getContainer()->get('doctrine.orm.entity_manager')->clear();
        $user = $this->users()->findByEmail(IdentityFixtures::EMAIL);
        self::assertNotNull($user);
        self::assertFalse($user->hasTwoFactor());
        self::assertNull($user->totpSecretSealed());
        self::assertSame(0, $user->recoveryCodesLeft());
        self::assertSame(1, $user->trustedDeviceVersion());
        self::assertStringContainsString(IdentityFixtures::EMAIL, $this->tester->getDisplay());
    }

    public function testAnUnknownUserFails(): void
    {
        self::assertSame(Command::FAILURE, $this->tester->execute(['email' => 'nobody@example.com']));
        self::assertStringContainsString('No user has this e-mail.', $this->tester->getDisplay());
    }

    private function users(): UserRepository
    {
        return self::getContainer()->get(UserRepository::class);
    }
}
