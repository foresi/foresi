<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\Shared\Infrastructure\Mcp;

use Foresi\Shared\Infrastructure\Mcp\ListModulesTool;
use Foresi\Shared\Infrastructure\Module\InstalledModules;
use Foresi\Shared\Infrastructure\Module\ModuleIntegrations;
use Foresi\Shared\Infrastructure\Module\ModulesView;
use PHPUnit\Framework\TestCase;

/**
 * The installed modules over MCP, the same data as bin/console app:modules
 * --format=json.
 */
final class ListModulesToolTest extends TestCase
{
    public function testItDescribesItselfWithoutArguments(): void
    {
        $tool = $this->tool();

        self::assertSame('foresi_list_modules', $tool->name());
        self::assertSame('List modules', $tool->title());
        self::assertStringStartsWith('Returns the modules installed', $tool->description());
        self::assertEquals(['type' => 'object', 'properties' => new \stdClass(), 'additionalProperties' => false], $tool->inputSchema());
    }

    public function testListsModulesAsJson(): void
    {
        $output = $this->tool()->call([]);

        $json = json_decode(json_encode($output->data, \JSON_THROW_ON_ERROR), true, flags: \JSON_THROW_ON_ERROR);
        self::assertIsArray($json);
        self::assertIsArray($json['modules']);
        $graph = $this->modules()->graph();
        $count = \count($graph->all());
        self::assertCount($count, $json['modules']);
        self::assertSame($this->modules()->acceptedProblems(), $json['problems']);
        $integrations = new ModuleIntegrations(\dirname(__DIR__, 4).'/src')->of($graph);
        self::assertSame(ModulesView::toArray($graph, $integrations), $output->data);
        self::assertIsArray($json['integrations']);
        // Party, which every application has, uses Import when it is installed.
        self::assertContains(['module' => 'party', 'uses' => 'import'], array_map(static fn (mixed $integration): array => \is_array($integration) ? ['module' => $integration['module'], 'uses' => $integration['uses']] : [], $json['integrations']));
        self::assertSame(\sprintf('%d installed modules, 0 problems in their dependencies.', $count), $output->summary);
    }

    private function tool(): ListModulesTool
    {
        return new ListModulesTool($this->modules(), new ModuleIntegrations(\dirname(__DIR__, 4).'/src'));
    }

    private function modules(): InstalledModules
    {
        return new InstalledModules(\dirname(__DIR__, 4));
    }
}
