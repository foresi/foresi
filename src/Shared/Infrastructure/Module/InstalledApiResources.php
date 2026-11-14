<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Shared\Infrastructure\Module;

use App\Shared\Application\Api\ApiResources;

/**
 * The API resources of the installed modules, as their manifests declare them.
 */
final class InstalledApiResources implements ApiResources
{
    /** @var list<string>|null */
    private ?array $resources = null;

    public function __construct(private readonly InstalledModules $modules)
    {
    }

    public function declared(): array
    {
        return $this->resources ??= $this->modules->graph()->resources();
    }
}
