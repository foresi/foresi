<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Shared\Domain\Module;

/**
 * What a module declares about itself: hard dependencies it cannot run
 * without, and optional ones it uses only when they are installed.
 */
final readonly class ModuleManifest
{
    /**
     * @param list<ModuleId> $requires
     * @param list<ModuleId> $optional
     */
    public function __construct(
        public ModuleId $id,
        public ModuleKind $kind,
        public string $package,
        public string $namespace,
        public array $requires,
        public array $optional,
    ) {
        foreach ([...$requires, ...$optional] as $dependency) {
            if ($dependency->equals($id)) {
                throw new InvalidManifest(\sprintf('Module %s depends on itself.', $id->value));
            }
        }
        foreach ($optional as $dependency) {
            foreach ($requires as $required) {
                if ($dependency->equals($required)) {
                    throw new InvalidManifest(\sprintf('Module %s lists %s as both required and optional.', $id->value, $dependency->value));
                }
            }
        }
    }
}
