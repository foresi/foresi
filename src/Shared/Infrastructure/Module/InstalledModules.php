<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Shared\Infrastructure\Module;

use App\Shared\Domain\Module\InvalidManifest;
use App\Shared\Domain\Module\ModuleGraph;
use App\Shared\Domain\Module\ModuleId;
use App\Shared\Domain\Module\ModuleKind;
use App\Shared\Domain\Module\ModuleManifest;
use Composer\InstalledVersions;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * The modules of this application: every package of type "foresi-module"
 * Composer installed. Being installed is being enabled; there is no other
 * switch. Each module's manifest is its composer.json: hard dependencies in
 * "require", the rest in "extra.foresi".
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
        if (!\is_string($namespace) || 1 !== preg_match('/^App\\\\[A-Z][A-Za-z0-9]*\\\\$/', $namespace)) {
            throw new InvalidManifest(\sprintf('Package %s has no valid extra.foresi.namespace such as "App\\\\Timesheet\\\\".', $package));
        }
        $optional = self::strings($extra['optional'] ?? [], \sprintf('Package %s: extra.foresi.optional must be a list of module ids.', $package));
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
        );
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
