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
        self::assertCount(19, $json['modules']);
        self::assertSame($this->modules()->acceptedProblems(), $json['problems']);
        $graph = $this->modules()->graph();
        self::assertSame(ModulesView::toArray($graph, new ModuleIntegrations(\dirname(__DIR__, 4).'/src')->of($graph)), $output->data);
        self::assertIsArray($json['integrations']);
        self::assertSame(['banking', 'invoicing', 'party', 'purchasing'], array_column($json['integrations'], 'module'));
        self::assertSame('19 installed modules, 0 problems in their dependencies.', $output->summary);
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
