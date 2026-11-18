<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Shared\Infrastructure\Mcp;

use Foresi\Shared\Application\Api\ScopeGuard;
use Foresi\Shared\Infrastructure\Module\InstalledModules;
use Foresi\Shared\Infrastructure\Module\ModuleIntegrations;
use Foresi\Shared\Infrastructure\Module\ModulesView;

/**
 * The modules of this installation and their dependencies, the same answer as
 * bin/console app:modules --format=json.
 */
final readonly class ListModulesTool implements ReadTool
{
    public function __construct(
        private InstalledModules $modules,
        private ModuleIntegrations $integrations,
    ) {
    }

    public function name(): string
    {
        return 'foresi_list_modules';
    }

    public function title(): string
    {
        return 'List modules';
    }

    public function scope(): string
    {
        return ScopeGuard::ANY;
    }

    public function description(): string
    {
        return 'Returns the modules installed in this Foresi application: id, kind (core, module or ui), package, required and optional modules, the integration code each module has for an optional one and whether it is loaded, and the known problems of the dependency graph. Installed means enabled; there is no other switch.';
    }

    public function inputSchema(): array
    {
        return ['type' => 'object', 'properties' => new \stdClass(), 'additionalProperties' => false];
    }

    public function call(array $arguments): ToolOutput
    {
        $graph = $this->modules->graph();
        $view = ModulesView::toArray($graph, $this->integrations->of($graph));

        return new ToolOutput(
            $view,
            \sprintf('%d installed modules, %d problems in their dependencies.', \count($view['modules']), \count($view['problems'])),
        );
    }
}
