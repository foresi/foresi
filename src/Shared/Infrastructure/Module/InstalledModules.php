<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Shared\Infrastructure\Module;

use Composer\InstalledVersions;
use Foresi\Shared\Domain\Module\InvalidManifest;
use Foresi\Shared\Domain\Module\ModuleGraph;
use Foresi\Shared\Domain\Module\ModuleId;
use Foresi\Shared\Domain\Module\ModuleKind;
use Foresi\Shared\Domain\Module\ModuleManifest;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * The modules of this application: every package of type "foresi-module"
 * Composer installed. Being installed is being enabled; there is no other
 * switch. Each module's manifest is its composer.json: hard dependencies in
 * "require", the rest in "extra.foresi", its API resources in "scopes" and
 * its Symfony bundle in "bundle". A resource a guest may hold is written as
 * {"resource": "tasks", "guest": "write"} (or "read") instead of "tasks"
 * (ADR 0041).
 */
final readonly class InstalledModules
{
    public const string TYPE = 'foresi-module';

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
        $packages = array_keys($composers);

        return new ModuleGraph(array_values(array_map(
            static fn (array $composer): ModuleManifest => self::manifestFrom($composer, $packages),
            $composers,
        )));
    }

    /**
     * The directory of an installed module's package, as Composer installed it:
     * vendor/foresi/<package> from Packagist, or the target of a path repository.
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
     * @param array<mixed> $composer       a decoded composer.json of type foresi-module
     * @param list<string> $modulePackages the installed module packages; other required packages are not modules
     */
    public static function manifestFrom(array $composer, array $modulePackages): ModuleManifest
    {
        $package = \is_string($composer['name'] ?? null) ? $composer['name'] : '(unnamed)';
        $extra = self::at($composer, 'extra', 'foresi');
        if (!\is_array($extra) || !\is_string($extra['module'] ?? null)) {
            throw new InvalidManifest(\sprintf('Package %s has no extra.foresi.module.', $package));
        }
        $id = ModuleId::of($extra['module']);
        if ('foresi/'.$id->value !== $package) {
            throw new InvalidManifest(\sprintf('Package %s must be named foresi/%s after its module id.', $package, $id->value));
        }
        $kind = ModuleKind::tryFrom(\is_string($extra['kind'] ?? null) ? $extra['kind'] : '')
            ?? throw new InvalidManifest(\sprintf('Package %s has no valid extra.foresi.kind (core, module or ui).', $package));
        $namespace = $extra['namespace'] ?? null;
        if (!\is_string($namespace) || 1 !== preg_match('/^Foresi\\\\[A-Z][A-Za-z0-9]*\\\\$/', $namespace)) {
            throw new InvalidManifest(\sprintf('Package %s has no valid extra.foresi.namespace such as "Foresi\\\\Timesheet\\\\".', $package));
        }
        $optional = self::strings($extra['optional'] ?? [], \sprintf('Package %s: extra.foresi.optional must be a list of module ids.', $package));
        [$scopes, $guestScopes] = self::scopes($extra['scopes'] ?? [], $package);
        $bundle = $extra['bundle'] ?? null;
        if (!\is_string($bundle)) {
            throw new InvalidManifest(\sprintf('Package %s has no extra.foresi.bundle, the class of its bundle such as "Foresi\\\\Timesheet\\\\TimesheetBundle".', $package));
        }
        $require = \is_array($composer['require'] ?? null) ? array_keys($composer['require']) : [];
        $requires = array_values(array_filter(
            array_map(strval(...), $require),
            static fn (string $name): bool => \in_array($name, $modulePackages, true),
        ));

        return new ModuleManifest(
            $id,
            $kind,
            $package,
            $namespace,
            array_map(static fn (string $name): ModuleId => ModuleId::of(substr($name, \strlen('foresi/'))), $requires),
            array_map(ModuleId::of(...), $optional),
            $scopes,
            $bundle,
            $guestScopes,
        );
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
