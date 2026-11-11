<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Tests\Shared\Infrastructure\Console;

use App\Shared\Domain\Module\ModuleGraph;
use App\Shared\Domain\Module\ModuleId;
use App\Shared\Domain\Module\ModuleKind;
use App\Shared\Domain\Module\ModuleManifest;
use App\Shared\Infrastructure\Console\ModulesCommand;
use App\Shared\Infrastructure\Module\InstalledModules;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * The installed modules from the shell; the exit code fails on a graph
 * problem the root composer.json does not accept.
 */
final class ModulesCommandTest extends TestCase
{
    public function testJsonListsAllModules(): void
    {
        $output = new BufferedOutput();

        $status = $this->command()($this->io($output), format: 'json');

        self::assertSame(Command::SUCCESS, $status);
        $json = json_decode($output->fetch(), true, flags: \JSON_THROW_ON_ERROR);
        self::assertIsArray($json);
        self::assertIsArray($json['modules']);
        self::assertCount(16, $json['modules']);
        $timesheet = array_values(array_filter($json['modules'], static fn (mixed $module): bool => \is_array($module) && 'timesheet' === $module['id']))[0] ?? null;
        self::assertIsArray($timesheet);
        self::assertSame('module', $timesheet['kind']);
        self::assertSame('foresi/timesheet', $timesheet['package']);
        self::assertIsArray($timesheet['requires']);
        self::assertContains('work', $timesheet['requires']);
        self::assertSame([], $timesheet['optional']);
        self::assertIsArray($json['problems']);
        self::assertContains('cycle:identity>party>identity', $json['problems']);
    }

    public function testTableShowsModulesAndProblems(): void
    {
        $output = new BufferedOutput();

        $status = $this->command()($this->io($output));

        self::assertSame(Command::SUCCESS, $status);
        $text = $output->fetch();
        self::assertStringContainsString('foresi/timesheet', $text);
        self::assertStringContainsString('cycle:identity>party>identity (accepted)', $text);
    }

    public function testOptionalDependenciesShowWhetherInstalled(): void
    {
        $output = new BufferedOutput();
        $graph = new ModuleGraph([
            $this->module('timesheet', optional: ['invoicing', 'work']),
            $this->module('work'),
        ]);

        $this->command()->show($this->io($output), $graph, [], 'table');

        self::assertStringContainsString('invoicing (absent), work (installed)', $output->fetch());
    }

    public function testUnacceptedProblemFails(): void
    {
        $output = new BufferedOutput();
        $graph = new ModuleGraph([
            $this->module('party', requires: ['work']),
            $this->module('work', requires: ['party']),
        ]);

        $status = $this->command()->show($this->io($output), $graph, [], 'table');

        self::assertSame(Command::FAILURE, $status);
        self::assertStringContainsString('cycle:party>work>party', $output->fetch());
    }

    public function testUnknownFormatIsRefused(): void
    {
        self::assertSame(Command::INVALID, $this->command()($this->io(new BufferedOutput()), format: 'xml'));
    }

    /**
     * @param list<string> $requires
     * @param list<string> $optional
     */
    private function module(string $id, array $requires = [], array $optional = []): ModuleManifest
    {
        return new ModuleManifest(
            ModuleId::of($id),
            ModuleKind::Module,
            'foresi/'.$id,
            'App\\'.ucfirst($id).'\\',
            array_map(ModuleId::of(...), $requires),
            array_map(ModuleId::of(...), $optional),
        );
    }

    private function command(): ModulesCommand
    {
        return new ModulesCommand(new InstalledModules(\dirname(__DIR__, 4)));
    }

    private function io(BufferedOutput $output): SymfonyStyle
    {
        return new SymfonyStyle(new ArrayInput([]), $output);
    }
}
