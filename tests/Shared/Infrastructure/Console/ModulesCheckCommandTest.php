<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\Shared\Infrastructure\Console;

use Foresi\Shared\Contract\ModuleApi;
use Foresi\Shared\Infrastructure\Console\ModulesCheckCommand;
use Foresi\Shared\Infrastructure\Module\InstalledModules;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * The module API check from the shell, which the deployment runs before the
 * migrations: non-zero when a module does not suit the version.
 */
final class ModulesCheckCommandTest extends TestCase
{
    public function testTheInstalledModulesSuitThisForesi(): void
    {
        $output = new BufferedOutput();

        self::assertSame(Command::SUCCESS, $this->command()($this->io($output)));
        self::assertStringContainsString('written for the module API '.ModuleApi::VERSION, $output->fetch());
    }

    public function testAnotherMajorNamesEveryModule(): void
    {
        $output = new BufferedOutput();

        self::assertSame(Command::FAILURE, $this->command()($this->io($output), api: '4.0'));
        $text = $output->fetch();
        self::assertStringContainsString('not written for the module API 4.0', $text);
        self::assertStringContainsString('shared (foresi/shared) requires foresi/module-api ^3.0.', $text);
    }

    public function testAVersionThatIsNoneIsInvalid(): void
    {
        $output = new BufferedOutput();

        self::assertSame(Command::INVALID, $this->command()($this->io($output), api: 'next'));
        self::assertStringContainsString('"next" is not a version', $output->fetch());
    }

    private function command(): ModulesCheckCommand
    {
        return new ModulesCheckCommand(new InstalledModules(\dirname(__DIR__, 4)));
    }

    private function io(BufferedOutput $output): SymfonyStyle
    {
        return new SymfonyStyle(new ArrayInput([]), $output);
    }
}
