<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

use Rector\Config\RectorConfig;
use Rector\DeadCode\Rector\ClassMethod\RemoveUnusedPrivateMethodParameterRector;
use Rector\PHPUnit\CodeQuality\Rector\Class_\PreferPHPUnitThisCallRector;
use Rector\Renaming\Rector\Name\RenameClassRector;

return RectorConfig::configure()
    ->withPaths([__DIR__.'/src', __DIR__.'/tests'])
    ->withSkip([
        __DIR__.'/tests/bootstrap.php',
        __DIR__.'/tests/object-manager.php',
        __DIR__.'/tests/e2e/node_modules',
        // Conflicts with phpstan-strict-rules (staticMethod.dynamicCall).
        PreferPHPUnitThisCallRector::class,
        // MicroKernelTrait passes the configurator, the loader and the builder by position.
        RemoveUnusedPrivateMethodParameterRector::class => [__DIR__.'/src/Shared/Infrastructure/Kernel/ForesiKernel.php'],
        // registerBundles() yields the HttpKernel bundle interface, which extends the one the rename points to.
        RenameClassRector::class => [__DIR__.'/src/Shared/Infrastructure/Kernel/ForesiKernel.php'],
    ])
    ->withPhpSets(php84: true)
    ->withComposerBased(twig: true, doctrine: true, phpunit: true, symfony: true)
    ->withPreparedSets(
        deadCode: true,
        codeQuality: true,
        typeDeclarations: true,
        symfonyCodeQuality: true,
        phpunitCodeQuality: true,
    );
