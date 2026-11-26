<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\Shared\Fixtures\Field;

use Foresi\Shared\Domain\Module\ModuleGraph;
use Foresi\Shared\Domain\Module\ModuleId;
use Foresi\Shared\Domain\Module\ModuleKind;
use Foresi\Shared\Domain\Module\ModuleManifest;
use Foresi\Shared\Infrastructure\Module\InstalledModules;

/**
 * The installed modules and, in the test environment only, the module "test-fields"
 * that owns the test-only custom fields of this directory.
 */
final class FixtureModules
{
    public const string ID = 'test-fields';

    public static function graph(InstalledModules $modules): ModuleGraph
    {
        return new ModuleGraph([...$modules->graph()->all(), self::manifest()]);
    }

    /**
     * @param string $namespace the namespace of its classes, this one's by default
     */
    public static function manifest(string $namespace = __NAMESPACE__.'\\'): ModuleManifest
    {
        return new ModuleManifest(ModuleId::of(self::ID), ModuleKind::Module, 'foresi-tests/fields', $namespace, [], []);
    }
}
