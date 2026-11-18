<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\Shared\Fixtures\ModuleIntegration\Alpha;

use Foresi\Shared\Domain\Module\ModuleGraph;
use Foresi\Shared\Infrastructure\Module\ModuleBundle;
use Symfony\Component\DependencyInjection\ContainerBuilder;

/**
 * The bundle of the fixture module Alpha, installed with the modules of the
 * graph it is given instead of the project's.
 */
final class AlphaBundle extends ModuleBundle
{
    public function __construct(
        private readonly ModuleGraph $graph,
    ) {
        parent::__construct();
    }

    public function loaded(string $module, ContainerBuilder $builder): bool
    {
        return $this->integrationLoaded($module, $builder);
    }

    protected function installedModules(ContainerBuilder $builder): ModuleGraph
    {
        return $this->graph;
    }
}
