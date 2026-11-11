<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Shared\Domain\Module;

/**
 * Something wrong with the module graph that does not stop the application,
 * identified by a stable code such as "cycle:identity>party>identity".
 */
final readonly class GraphProblem
{
    private function __construct(
        public string $code,
    ) {
    }

    /**
     * @param list<ModuleId> $path the modules along the cycle, starting with the smallest id
     */
    public static function cycle(array $path): self
    {
        $ids = array_map(static fn (ModuleId $id): string => $id->value, $path);

        return new self('cycle:'.implode('>', [...$ids, $ids[0] ?? '']));
    }

    public static function coreRequiresModule(ModuleId $core, ModuleId $module): self
    {
        return new self(\sprintf('core-requires-module:%s>%s', $core->value, $module->value));
    }
}
