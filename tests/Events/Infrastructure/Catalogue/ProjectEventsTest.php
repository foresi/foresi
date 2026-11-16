<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Tests\Events\Infrastructure\Catalogue;

use App\Events\Infrastructure\Catalogue\EventCatalogue;
use App\Shared\Infrastructure\Module\InstalledModules;
use PHPUnit\Framework\TestCase;

/**
 * The contract events of this repository follow the rules of the catalogue.
 * The fixtures of the tests are outside the module directories, so they never
 * show up here.
 */
final class ProjectEventsTest extends TestCase
{
    public function testCatalogueHasNoProblems(): void
    {
        $projectDir = \dirname(__DIR__, 4);
        $catalogue = new EventCatalogue(new InstalledModules($projectDir), $projectDir);

        self::assertSame([], $catalogue->problems());
        foreach ($catalogue->all() as $type) {
            self::assertStringStartsWith($type->module->value.'.', $type->name);
            self::assertStringNotContainsString('Fixtures', $type->class);
        }
    }
}
