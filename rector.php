<?php
// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

use Rector\Config\RectorConfig;
use Rector\PHPUnit\CodeQuality\Rector\Class_\PreferPHPUnitThisCallRector;

return RectorConfig::configure()
    ->withPaths([__DIR__.'/src', __DIR__.'/tests'])
    ->withSkip([
        __DIR__.'/tests/bootstrap.php',
        __DIR__.'/tests/object-manager.php',
        __DIR__.'/tests/e2e/node_modules',
        // Conflicts with phpstan-strict-rules (staticMethod.dynamicCall).
        PreferPHPUnitThisCallRector::class,
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
