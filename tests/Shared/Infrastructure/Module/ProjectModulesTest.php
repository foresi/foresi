<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\Shared\Infrastructure\Module;

use Foresi\Shared\Domain\Module\GraphProblem;
use Foresi\Shared\Domain\Module\ModuleGraph;
use Foresi\Shared\Domain\Module\ModuleId;
use Foresi\Shared\Domain\Module\ModuleManifest;
use Foresi\Shared\Infrastructure\Module\InstalledModules;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

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

    /**
     * An interface is bound to a class of its own module or of one the module
     * requires (ADR 0034, rule 3): a port served by another module collects its
     * providers by tag, so the container never needs a class of an absent module.
     */
    public function testServiceAliasesStayWithinRequiredModules(): void
    {
        $graph = new InstalledModules($this->projectDir())->graph();
        $config = Yaml::parseFile($this->projectDir().'/config/services.yaml', Yaml::PARSE_CUSTOM_TAGS);
        self::assertIsArray($config);
        $services = $config['services'] ?? null;
        self::assertIsArray($services);

        $crossing = [];
        foreach ($services as $id => $definition) {
            $alias = \is_array($definition) ? ($definition['alias'] ?? null) : null;
            if (!\is_string($id) || !\is_string($alias)) {
                continue;
            }
            $owner = $this->moduleOf($graph, $id);
            $target = $this->moduleOf($graph, $alias);
            if ($owner instanceof ModuleManifest && $target instanceof ModuleManifest && !\in_array($target->id->value, $this->closure($graph, $owner->id), true)) {
                $crossing[] = \sprintf('%s -> %s', $id, $alias);
            }
        }

        self::assertSame([], $crossing);
    }

    private function moduleOf(ModuleGraph $graph, string $class): ?ModuleManifest
    {
        foreach ($graph->all() as $module) {
            if (str_starts_with($class, $module->namespace)) {
                return $module;
            }
        }

        return null;
    }

    /**
     * @return list<string> the module and every module it requires, directly or not
     */
    private function closure(ModuleGraph $graph, ModuleId $id): array
    {
        $seen = [$id->value => true];
        $queue = [$id];
        while ([] !== $queue) {
            foreach ($graph->get(array_pop($queue))->requires as $dependency) {
                if (!isset($seen[$dependency->value])) {
                    $seen[$dependency->value] = true;
                    $queue[] = $dependency;
                }
            }
        }

        return array_keys($seen);
    }

    private function projectDir(): string
    {
        return \dirname(__DIR__, 4);
    }
}
