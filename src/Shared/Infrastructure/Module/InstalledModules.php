<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Shared\Infrastructure\Module;

use Composer\InstalledVersions;
use Foresi\Shared\Contract\ModuleApi;
use Foresi\Shared\Domain\Module\InvalidManifest;
use Foresi\Shared\Domain\Module\ModuleGraph;
use Foresi\Shared\Domain\Module\ModuleId;
use Foresi\Shared\Domain\Module\ModuleKind;
use Foresi\Shared\Domain\Module\ModuleManifest;
use Foresi\Shared\Domain\Module\ModuleNamespace;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * The modules of this application: every package of type "foresi-module"
 * Composer installed, of any vendor. Being installed is being enabled; there is no other
 * switch. Each module's manifest is its composer.json: hard dependencies in
 * "require", the rest in "extra.foresi", its API resources in "scopes" and
 * its Symfony bundle in "bundle". A resource a guest may hold is written as
 * {"resource": "tasks", "guest": "write"} (or "read") instead of "tasks"
 * (ADR 0041).
 */
final readonly class InstalledModules
{
    public const string TYPE = 'foresi-module';

    /** A Composer package name. */
    private const string PACKAGE = '{^[a-z0-9]([_.-]?[a-z0-9]+)*/[a-z0-9](([_.]|-{1,2})?[a-z0-9]+)*$}';

    public function __construct(
        #[Autowire(param: 'kernel.project_dir')]
        private string $projectDir,
    ) {
    }

    public function graph(): ModuleGraph
    {
        $composers = [];
        foreach (InstalledVersions::getInstalledPackagesByType(self::TYPE) as $package) {
            $path = InstalledVersions::getInstallPath($package)
                ?? throw new InvalidManifest(\sprintf('Package %s has no install path.', $package));
            $composers[$package] = $this->read($path.'/composer.json');
        }
        $ids = [];
        foreach ($composers as $package => $composer) {
            $id = self::at($composer, 'extra', 'foresi', 'module');
            if (\is_string($id)) {
                $ids[$package] = $id;
            }
        }

        return new ModuleGraph(array_values(array_map(
            static fn (array $composer): ModuleManifest => self::manifestFrom($composer, $ids),
            $composers,
        )));
    }

    /**
     * The directory of an installed module's package, as Composer installed it:
     * vendor/<vendor>/<package> from Packagist, or the target of a path repository.
     * Nothing assumes the module sits in src/ of the application.
     *
     * @throws InvalidManifest when the module's package is not installed
     */
    public static function directoryOf(ModuleManifest $module): string
    {
        $path = InstalledVersions::isInstalled($module->package) ? InstalledVersions::getInstallPath($module->package) : null;
        $directory = null === $path ? false : realpath($path);

        return false === $directory ? throw new InvalidManifest(\sprintf('Module %s (package %s) is not installed.', $module->id->value, $module->package)) : $directory;
    }

    /**
     * The graph problems this application lives with for now, from
     * "extra.foresi.accepted-problems" of the root composer.json.
     *
     * @return list<string>
     */
    public function acceptedProblems(): array
    {
        return self::strings(
            self::at($this->read($this->projectDir.'/composer.json'), 'extra', 'foresi', 'accepted-problems') ?? [],
            'extra.foresi.accepted-problems of the root composer.json must be a list of problem codes.',
        );
    }

    /**
     * A module of any vendor (ADR 0044): the package is any "vendor/name",
     * and one of Foresi's own is "foresi/<id>"; the namespace is a PSR-4 prefix
     * of the package's autoload, "Foresi\<Module>\" for Foresi's own and
     * "Vendor\Module\" for others, and gives the id and the bundle's class
     * (ModuleNamespace).
     *
     * @param array<mixed>          $composer  a decoded composer.json of type foresi-module
     * @param array<string, string> $moduleIds the id of each installed module, by its package; other required packages are not modules
     */
    public static function manifestFrom(array $composer, array $moduleIds): ModuleManifest
    {
        $package = \is_string($composer['name'] ?? null) ? $composer['name'] : '(unnamed)';
        if (1 !== preg_match(self::PACKAGE, $package)) {
            throw new InvalidManifest(\sprintf('Package %s has no valid name such as "acme/fleet".', $package));
        }
        $extra = self::at($composer, 'extra', 'foresi');
        if (!\is_array($extra) || !\is_string($extra['module'] ?? null)) {
            throw new InvalidManifest(\sprintf('Package %s has no extra.foresi.module.', $package));
        }
        $id = ModuleId::of($extra['module']);
        $own = str_starts_with($package, ModuleNamespace::OWN_VENDOR.'/');
        if ($own && ModuleNamespace::OWN_VENDOR.'/'.$id->value !== $package) {
            throw new InvalidManifest(\sprintf('Package %s must be named foresi/%s after its module id.', $package, $id->value));
        }
        $kind = ModuleKind::tryFrom(\is_string($extra['kind'] ?? null) ? $extra['kind'] : '')
            ?? throw new InvalidManifest(\sprintf('Package %s has no valid extra.foresi.kind (core, module or ui).', $package));
        $namespace = self::namespace($composer, $extra['namespace'] ?? null, $package, $own);
        if (!$namespace->id()->equals($id)) {
            throw new InvalidManifest(\sprintf('Package %s declares the module id "%s", but its namespace %s gives the id "%s".', $package, $id->value, $namespace->value, $namespace->id()->value));
        }
        $optional = self::strings($extra['optional'] ?? [], \sprintf('Package %s: extra.foresi.optional must be a list of module ids.', $package));
        [$scopes, $guestScopes] = self::scopes($extra['scopes'] ?? [], $package);
        $bundle = $extra['bundle'] ?? null;
        if ($namespace->bundle() !== $bundle) {
            throw new InvalidManifest(\sprintf('Package %s must name its bundle in extra.foresi.bundle, the class "%s".', $package, str_replace('\\', '\\\\', $namespace->bundle())));
        }
        $require = \is_array($composer['require'] ?? null) ? $composer['require'] : [];
        $requires = [];
        foreach (array_keys($require) as $name) {
            if (isset($moduleIds[$name])) {
                $requires[] = ModuleId::of($moduleIds[$name]);
            }
        }
        $api = $require[ModuleApi::PACKAGE] ?? null;
        if (null !== $api && !\is_string($api)) {
            throw new InvalidManifest(\sprintf('Package %s requires %s without a version range such as "^1.0".', $package, ModuleApi::PACKAGE));
        }

        return new ModuleManifest(
            $id,
            $kind,
            $package,
            $namespace->value,
            $requires,
            array_map(ModuleId::of(...), $optional),
            $scopes,
            $bundle,
            $guestScopes,
            $api,
        );
    }

    /**
     * The namespace of extra.foresi.namespace, which must be a PSR-4 prefix of
     * the package's autoload.
     *
     * @param array<mixed> $composer
     */
    private static function namespace(array $composer, mixed $value, string $package, bool $own): ModuleNamespace
    {
        if (!\is_string($value)) {
            throw new InvalidManifest(\sprintf('Package %s has no extra.foresi.namespace such as "Acme\\\\Fleet\\\\".', $package));
        }
        $namespace = ModuleNamespace::of($value);
        if ($own !== $namespace->own() || ($own && !$namespace->ofOwnPackage())) {
            throw new InvalidManifest($own ? \sprintf('Package %s is one of Foresi\'s own, so its namespace is "Foresi\\\\<Module>\\\\", such as "Foresi\\\\Timesheet\\\\".', $package) : \sprintf('Package %s is not one of Foresi\'s own, so its namespace cannot start with "Foresi\\\\".', $package));
        }
        $psr4 = self::at($composer, 'autoload', 'psr-4');
        if (!\is_array($psr4) || !\array_key_exists($value, $psr4)) {
            throw new InvalidManifest(\sprintf('Package %s declares the namespace %s, which its autoload.psr-4 does not.', $package, $value));
        }

        return $namespace;
    }

    /**
     * The resources of "extra.foresi.scopes", and those a guest may hold.
     *
     * @return array{list<string>, array<string, bool>}
     */
    private static function scopes(mixed $value, string $package): array
    {
        $error = \sprintf('Package %s: extra.foresi.scopes must be a list of resources, each "tasks" or {"resource": "tasks", "guest": "read" or "write"}.', $package);
        if (!\is_array($value) || !array_is_list($value)) {
            throw new InvalidManifest($error);
        }
        $scopes = [];
        $guest = [];
        foreach ($value as $item) {
            if (\is_string($item)) {
                $scopes[] = $item;
                continue;
            }
            if (!\is_array($item) || !\is_string($item['resource'] ?? null) || !\in_array($item['guest'] ?? null, ['read', 'write'], true) || [] !== array_diff(array_keys($item), ['resource', 'guest'])) {
                throw new InvalidManifest($error);
            }
            $scopes[] = $item['resource'];
            $guest[$item['resource']] = 'write' === $item['guest'];
        }

        return [$scopes, $guest];
    }

    /**
     * The value at a path of nested keys, or null when a step is missing.
     *
     * @param array<mixed> $data
     */
    private static function at(array $data, string ...$keys): mixed
    {
        $value = $data;
        foreach ($keys as $key) {
            if (!\is_array($value) || !\array_key_exists($key, $value)) {
                return null;
            }
            $value = $value[$key];
        }

        return $value;
    }

    /**
     * @return list<string>
     */
    private static function strings(mixed $value, string $error): array
    {
        if (!\is_array($value) || !array_is_list($value)) {
            throw new InvalidManifest($error);
        }
        $strings = [];
        foreach ($value as $item) {
            $strings[] = \is_string($item) ? $item : throw new InvalidManifest($error);
        }

        return $strings;
    }

    /**
     * @return array<mixed>
     */
    private function read(string $file): array
    {
        $json = @file_get_contents($file);
        if (false === $json) {
            throw new InvalidManifest(\sprintf('Cannot read %s.', $file));
        }
        $composer = json_decode($json, true, flags: \JSON_THROW_ON_ERROR);

        return \is_array($composer) ? $composer : throw new InvalidManifest(\sprintf('%s is not a JSON object.', $file));
    }
}
