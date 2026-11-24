<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

/*
 * Templates of test doubles, such as a panel a test adds to a screen slot. A path is added only while its module's
 * tests are there: an application with a subset of the modules has no tests of the others (bin/module-subset prune).
 */
return static function (ContainerConfigurator $container): void {
    $paths = [];
    foreach (['CashFlow/Support/templates' => 'CashFlowTestSupport'] as $directory => $namespace) {
        $path = dirname(__DIR__, 3).'/tests/'.$directory;
        if (is_dir($path)) {
            $paths[$path] = $namespace;
        }
    }
    if ([] !== $paths) {
        $container->extension('twig', ['paths' => $paths]);
    }
};
