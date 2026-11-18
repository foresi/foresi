<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\Shared\Infrastructure\Console;

use Foresi\Shared\Domain\Module\ModuleGraph;
use Foresi\Shared\Domain\Module\ModuleId;
use Foresi\Shared\Domain\Module\ModuleKind;
use Foresi\Shared\Domain\Module\ModuleManifest;
use Foresi\Shared\Infrastructure\Console\ModulesCommand;
use Foresi\Shared\Infrastructure\Module\InstalledModules;
use Foresi\Shared\Infrastructure\Module\ModuleIntegration;
use Foresi\Shared\Infrastructure\Module\ModuleIntegrations;
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
        self::assertCount(19, $json['modules']);
        $timesheet = array_values(array_filter($json['modules'], static fn (mixed $module): bool => \is_array($module) && 'timesheet' === $module['id']))[0] ?? null;
        self::assertIsArray($timesheet);
        self::assertSame('module', $timesheet['kind']);
        self::assertSame('foresi/timesheet', $timesheet['package']);
        self::assertIsArray($timesheet['requires']);
        self::assertContains('work', $timesheet['requires']);
        self::assertSame([], $timesheet['optional']);
        self::assertIsArray($json['problems']);
        self::assertContains('core-requires-module:webhooks>ui', $json['problems']);
    }

    public function testTableShowsModulesAndProblems(): void
    {
        $output = new BufferedOutput();

        $status = $this->command()($this->io($output));

        self::assertSame(Command::SUCCESS, $status);
        $text = $output->fetch();
        self::assertStringContainsString('foresi/timesheet', $text);
        self::assertStringContainsString('core-requires-module:webhooks>ui (accepted)', $text);
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

    public function testIntegrationsShowWhetherLoaded(): void
    {
        $output = new BufferedOutput();
        $graph = new ModuleGraph([$this->module('invoicing', optional: ['cash-flow', 'work']), $this->module('work')]);
        $integrations = [
            new ModuleIntegration(ModuleId::of('invoicing'), ModuleId::of('cash-flow'), 'Foresi\\Invoicing\\Integration\\CashFlow\\', '/src/Invoicing/Integration/CashFlow', false),
            new ModuleIntegration(ModuleId::of('invoicing'), ModuleId::of('work'), 'Foresi\\Invoicing\\Integration\\Work\\', '/src/Invoicing/Integration/Work', true),
        ];

        $this->command()->show($this->io($output), $graph, [], 'table', $integrations);
        $text = $output->fetch();
        $this->command()->show($this->io($output), $graph, [], 'json', $integrations);
        $json = json_decode($output->fetch(), true, flags: \JSON_THROW_ON_ERROR);

        self::assertStringContainsString('invoicing uses cash-flow through Foresi\\Invoicing\\Integration\\CashFlow\\ (not loaded, cash-flow is absent)', $text);
        self::assertStringContainsString('invoicing uses work through Foresi\\Invoicing\\Integration\\Work\\ (loaded)', $text);
        self::assertIsArray($json);
        self::assertSame(
            [
                ['module' => 'invoicing', 'uses' => 'cash-flow', 'namespace' => 'Foresi\\Invoicing\\Integration\\CashFlow\\', 'loaded' => false],
                ['module' => 'invoicing', 'uses' => 'work', 'namespace' => 'Foresi\\Invoicing\\Integration\\Work\\', 'loaded' => true],
            ],
            $json['integrations'],
        );
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
            'Foresi\\'.ucfirst($id).'\\',
            array_map(ModuleId::of(...), $requires),
            array_map(ModuleId::of(...), $optional),
        );
    }

    private function command(): ModulesCommand
    {
        return new ModulesCommand(new InstalledModules(\dirname(__DIR__, 4)), new ModuleIntegrations(\dirname(__DIR__, 4).'/src'));
    }

    private function io(BufferedOutput $output): SymfonyStyle
    {
        return new SymfonyStyle(new ArrayInput([]), $output);
    }
}
