<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Shared\Infrastructure\Module;

use Foresi\Shared\Application\Api\ApiResources;

/**
 * The API resources of the installed modules, as their manifests declare them.
 */
final class InstalledApiResources implements ApiResources
{
    /** @var list<string>|null */
    private ?array $resources = null;

    /** @var list<string>|null */
    private ?array $guest = null;

    public function __construct(private readonly InstalledModules $modules)
    {
    }

    public function declared(): array
    {
        return $this->resources ??= $this->modules->graph()->resources();
    }

    public function byModule(): array
    {
        $modules = [];
        foreach ($this->modules->graph()->all() as $module) {
            if ([] !== $module->scopes) {
                $resources = $module->scopes;
                sort($resources, \SORT_STRING);
                $modules[$module->id->value] = $resources;
            }
        }

        return $modules;
    }

    public function guest(): array
    {
        return $this->guest ??= $this->modules->graph()->guestScopes();
    }
}
