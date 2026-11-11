<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Tests\Shared\Infrastructure\Module;

use App\Shared\Domain\Module\GraphProblem;
use App\Shared\Infrastructure\Module\InstalledModules;
use PHPUnit\Framework\TestCase;

/**
 * The modules of this repository: every directory under src/ is a module with
 * a manifest, and the graph problems are exactly the ones the root
 * composer.json accepts for now, so a fixed problem must leave the list too.
 */
final class ProjectModulesTest extends TestCase
{
    public function testEverySrcDirectoryIsAModule(): void
    {
        $directories = array_filter(
            (array) glob($this->projectDir().'/src/*', \GLOB_ONLYDIR),
            static fn (mixed $directory): bool => \is_string($directory) && 'Controller' !== basename($directory),
        );
        self::assertNotEmpty($directories);

        foreach ($directories as $directory) {
            self::assertFileExists($directory.'/composer.json', \sprintf('%s has no module manifest.', basename($directory)));
            $composer = json_decode((string) file_get_contents($directory.'/composer.json'), false, flags: \JSON_THROW_ON_ERROR);
            self::assertInstanceOf(\stdClass::class, $composer);
            self::assertSame(InstalledModules::TYPE, $composer->type ?? null, \sprintf('%s is not of type %s.', basename($directory), InstalledModules::TYPE));
        }
        self::assertCount(\count($directories), new InstalledModules($this->projectDir())->graph()->all(), 'Every manifest must be installed; run composer update "foresi/*".');
    }

    public function testProblemsAreExactlyTheAcceptedOnes(): void
    {
        $modules = new InstalledModules($this->projectDir());
        $problems = array_map(static fn (GraphProblem $problem): string => $problem->code, $modules->graph()->problems());
        $accepted = $modules->acceptedProblems();
        sort($accepted);

        self::assertSame($accepted, $problems);
    }

    private function projectDir(): string
    {
        return \dirname(__DIR__, 4);
    }
}
