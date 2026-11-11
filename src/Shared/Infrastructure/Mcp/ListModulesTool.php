<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Shared\Infrastructure\Mcp;

use App\Shared\Infrastructure\Module\InstalledModules;
use App\Shared\Infrastructure\Module\ModulesView;

/**
 * The modules of this installation and their dependencies, the same answer as
 * bin/console app:modules --format=json.
 */
final readonly class ListModulesTool implements ReadTool
{
    public function __construct(private InstalledModules $modules)
    {
    }

    public function name(): string
    {
        return 'foresi_list_modules';
    }

    public function title(): string
    {
        return 'List modules';
    }

    public function description(): string
    {
        return 'Returns the modules installed in this Foresi application: id, kind (core, module or ui), package, required and optional modules, and the known problems of the dependency graph. Installed means enabled; there is no other switch.';
    }

    public function inputSchema(): array
    {
        return ['type' => 'object', 'properties' => new \stdClass(), 'additionalProperties' => false];
    }

    public function call(array $arguments): ToolOutput
    {
        $view = ModulesView::toArray($this->modules->graph());

        return new ToolOutput(
            $view,
            \sprintf('%d installed modules, %d problems in their dependencies.', \count($view['modules']), \count($view['problems'])),
        );
    }
}
