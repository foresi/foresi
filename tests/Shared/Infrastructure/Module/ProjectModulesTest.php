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

    /**
     * Every package is split into its own repository and installed from
     * Packagist.org (ADR 0034, Distribution), so each one must stand on its own:
     * the licence text travels with it, and the packages of this repository
     * require each other at the same version, as they are released in lockstep.
     */
    public function testEveryPackageCanBePublishedOnItsOwn(): void
    {
        $licence = (string) file_get_contents($this->projectDir().'/LICENSE');
        $manifests = [...(array) glob($this->projectDir().'/src/*/composer.json'), $this->projectDir().'/packages/core/composer.json'];

        foreach ($manifests as $manifest) {
            $directory = \dirname((string) $manifest);
            $package = basename($directory);
            $composer = json_decode((string) file_get_contents((string) $manifest), true, flags: \JSON_THROW_ON_ERROR);
            self::assertIsArray($composer);

            $name = $composer['name'] ?? null;
            self::assertIsString($name, $package);
            self::assertMatchesRegularExpression('#^foresi/[a-z0-9]+(-[a-z0-9]+)*$#', $name, $package);
            self::assertSame('AGPL-3.0-only', $composer['license'] ?? null, $package);
            $support = $composer['support'] ?? null;
            self::assertIsArray($support, $package);
            self::assertSame('https://github.com/foresi/foresi', $support['source'] ?? null, \sprintf('%s must point to this repository, where it is developed.', $package));
            self::assertFileExists($directory.'/LICENSE', \sprintf('%s ships without the licence text; copy LICENSE into it.', $package));
            self::assertSame($licence, file_get_contents($directory.'/LICENSE'), \sprintf('%s/LICENSE differs from LICENSE.', $package));
            foreach ((array) ($composer['require'] ?? []) as $dependency => $constraint) {
                if (str_starts_with((string) $dependency, 'foresi/')) {
                    self::assertSame('self.version', $constraint, \sprintf('%s must require %s at self.version.', $package, $dependency));
                }
            }
        }
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
     * The interfaces the container bound implicitly, as their only
     * implementation was in another module, before each module loaded its
     * services on its own. They are bound explicitly now, and each one is to
     * become a port with a default (ADR 0034, rule 3); a binding fixed that
     * way must leave this list too.
     */
    private const array IMPLICIT_PORTS = [
        'Foresi\\Audit\\Application\\AuditActor -> Foresi\\Identity\\Infrastructure\\Security\\SecurityAuditActor',
        'Foresi\\Audit\\Application\\TokenAuditActor -> Foresi\\Identity\\Infrastructure\\Security\\SecurityAuditActor',
        'Foresi\\Identity\\Contract\\GuestHome -> Foresi\\Work\\Infrastructure\\Identity\\SharedWorkGuestHome',
        'Foresi\\Shared\\Application\\ActorRole -> Foresi\\Identity\\Infrastructure\\Security\\MembershipActorRole',
        'Foresi\\Shared\\Application\\Api\\ApiClient -> Foresi\\Identity\\Infrastructure\\Security\\TokenApiClient',
        'Foresi\\Shared\\Application\\Api\\IdempotencyKeys -> Foresi\\Identity\\Infrastructure\\Idempotency\\DbalIdempotencyKeys',
        'Foresi\\Shared\\Application\\Api\\ScopeGuard -> Foresi\\Identity\\Infrastructure\\Security\\TokenScopeGuard',
        'Foresi\\Shared\\Application\\Event\\EventOutbox -> Foresi\\Events\\Infrastructure\\Dbal\\DbalEventRecorder',
        'Foresi\\Shared\\Application\\Event\\EventRecorder -> Foresi\\Events\\Infrastructure\\Dbal\\DbalEventRecorder',
        'Foresi\\Shared\\Infrastructure\\Mcp\\ToolCaller -> Foresi\\Identity\\Infrastructure\\Console\\McpStdioSignIn',
    ];

    /**
     * An interface is bound to a class of its own module or of one the module
     * requires (ADR 0034, rule 3): a port served by another module collects its
     * providers by tag, so the container never needs a class of an absent module.
     * The bindings are read from config/services.yaml and from each module's
     * config/services.php.
     */
    public function testServiceAliasesStayWithinRequiredModules(): void
    {
        $graph = new InstalledModules($this->projectDir())->graph();
        $crossing = [];
        foreach ($this->aliases() as $id => $alias) {
            $owner = $this->moduleOf($graph, $id);
            $target = $this->moduleOf($graph, $alias);
            if ($owner instanceof ModuleManifest && $target instanceof ModuleManifest && !\in_array($target->id->value, $this->closure($graph, $owner->id), true)) {
                $crossing[] = \sprintf('%s -> %s', $id, $alias);
            }
        }
        sort($crossing);
        // The known ones whose two modules are installed.
        $known = array_values(array_filter(self::IMPLICIT_PORTS, fn (string $port): bool => [] === array_filter(explode(' -> ', $port), fn (string $class): bool => !$this->moduleOf($graph, $class) instanceof ModuleManifest)));

        self::assertSame($known, $crossing);
    }

    /**
     * @return array<string, string> the aliased id and the id it points to
     */
    private function aliases(): array
    {
        $config = Yaml::parseFile($this->projectDir().'/config/services.yaml', Yaml::PARSE_CUSTOM_TAGS);
        self::assertIsArray($config);
        $aliases = [];
        foreach ((array) ($config['services'] ?? []) as $id => $definition) {
            $alias = \is_array($definition) ? ($definition['alias'] ?? null) : null;
            if (\is_string($id) && \is_string($alias)) {
                $aliases[$id] = $alias;
            }
        }
        foreach ((array) glob($this->projectDir().'/src/*/config/services.php') as $file) {
            $code = (string) file_get_contents((string) $file);
            preg_match_all('/^use ([A-Za-z0-9\\\\]+);$/m', $code, $uses, \PREG_SET_ORDER);
            $classes = [];
            foreach ($uses as [, $class]) {
                $classes[substr($class, (int) strrpos($class, '\\') + 1)] = $class;
            }
            preg_match_all('/->alias\((\w+)::class, (\w+)::class\)/', $code, $calls, \PREG_SET_ORDER);
            foreach ($calls as [, $id, $alias]) {
                $aliases[$classes[$id] ?? $id] = $classes[$alias] ?? $alias;
            }
        }

        return $aliases;
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
