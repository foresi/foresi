<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Shared\Infrastructure\Module;

use App\Shared\Domain\Module\GraphProblem;
use App\Shared\Domain\Module\ModuleGraph;
use App\Shared\Domain\Module\ModuleId;
use App\Shared\Domain\Module\ModuleManifest;

/**
 * The installed modules as plain data, the same for the console and for MCP.
 */
final class ModulesView
{
    /**
     * @return array{modules: list<array{id: string, kind: string, package: string, requires: list<string>, optional: list<string>}>, problems: list<string>}
     */
    public static function toArray(ModuleGraph $graph): array
    {
        return [
            'modules' => array_map(static fn (ModuleManifest $module): array => [
                'id' => $module->id->value,
                'kind' => $module->kind->value,
                'package' => $module->package,
                'requires' => self::ids($module->requires),
                'optional' => self::ids($module->optional),
            ], $graph->all()),
            'problems' => array_map(static fn (GraphProblem $problem): string => $problem->code, $graph->problems()),
        ];
    }

    /**
     * The problems of the graph the application does not accept.
     *
     * @param list<string> $accepted
     *
     * @return list<string>
     */
    public static function unaccepted(ModuleGraph $graph, array $accepted): array
    {
        return array_values(array_filter(
            self::toArray($graph)['problems'],
            static fn (string $code): bool => !\in_array($code, $accepted, true),
        ));
    }

    /**
     * @param list<ModuleId> $ids
     *
     * @return list<string> sorted
     */
    private static function ids(array $ids): array
    {
        $values = array_map(static fn (ModuleId $id): string => $id->value, $ids);
        sort($values, \SORT_STRING);

        return $values;
    }
}
