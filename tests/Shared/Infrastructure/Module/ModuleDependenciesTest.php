<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\Shared\Infrastructure\Module;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Each module's composer.json requires exactly the third-party packages its own
 * PHP code uses (ADR 0034), so "composer require foresi/<module>" installs what
 * the module needs and nothing it does not. A class counts as used when a file
 * of the module imports it ("use") or names it fully qualified ("\Vendor\...");
 * its package is the one whose PSR-4 prefix is the longest match. Bundles the
 * application's config/ needs, and PHP extensions, are the application's.
 */
final class ModuleDependenciesTest extends TestCase
{
    /**
     * @var array<string, string>|null namespace prefix => package, longest first
     */
    private static ?array $packages = null;

    /**
     * @return iterable<string, array{string}>
     */
    public static function modules(): iterable
    {
        $files = glob(self::root().'/src/*/composer.json');
        foreach (false === $files ? [] : $files as $file) {
            yield basename(\dirname($file)) => [\dirname($file)];
        }
    }

    #[DataProvider('modules')]
    public function testTheModuleRequiresThePackagesItsCodeUses(string $directory): void
    {
        // A subset of modules (bin/module-subset) lacks the packages only absent modules
        // bring, so a class of theirs would map to no package; the whole application has them all.
        if ('foresi/foresi' !== (self::json(self::root().'/composer.json')['name'] ?? null)) {
            self::markTestSkipped('Only the application with every module installed knows every package.');
        }
        $require = self::json($directory.'/composer.json')['require'] ?? [];
        $declared = array_values(array_filter(
            array_map(strval(...), array_keys(\is_array($require) ? $require : [])),
            static fn (string $package): bool => str_contains($package, '/') && !str_starts_with($package, 'foresi/'),
        ));
        sort($declared);

        self::assertSame(self::used($directory), $declared, 'The "require" of '.basename($directory).'/composer.json lists the third-party packages its code uses, no more and no fewer.');
    }

    /**
     * @return list<string> sorted
     */
    public static function used(string $directory): array
    {
        $used = [];
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS));
        foreach ($files as $file) {
            if (!$file instanceof \SplFileInfo || 'php' !== $file->getExtension()) {
                continue;
            }
            $code = (string) file_get_contents($file->getPathname());
            preg_match_all('/^use\s+(?:function\s+|const\s+)?\\\\?([A-Za-z_][\w\\\\]*)/m', $code, $imports);
            preg_match_all('/(?<![\w\\\\])\\\\([A-Z][\w]*\\\\[\w\\\\]+)/', $code, $qualified);
            foreach ([...$imports[1], ...$qualified[1]] as $class) {
                $package = self::packageOf($class);
                if (null !== $package && !str_starts_with($package, 'foresi/')) {
                    $used[$package] = true;
                }
            }
        }
        $used = array_keys($used);
        sort($used);

        return $used;
    }

    private static function packageOf(string $class): ?string
    {
        foreach (self::packages() as $prefix => $package) {
            if (str_starts_with($class, $prefix)) {
                return $package;
            }
        }

        return null;
    }

    /**
     * @return array<string, string>
     */
    private static function packages(): array
    {
        if (null !== self::$packages) {
            return self::$packages;
        }
        $vendor = self::root().'/vendor';
        $directories = [];
        $installed = self::json($vendor.'/composer/installed.json')['packages'] ?? [];
        foreach (\is_array($installed) ? $installed : [] as $package) {
            if (!\is_array($package) || !\is_string($package['install-path'] ?? null) || !\is_string($package['name'] ?? null)) {
                continue;
            }
            $path = realpath($vendor.'/composer/'.$package['install-path']);
            if (false !== $path) {
                $directories[$path] = $package['name'];
            }
        }
        $packages = [];
        /** @var array<string, list<string>> $psr4 */
        $psr4 = require $vendor.'/composer/autoload_psr4.php';
        foreach ($psr4 as $prefix => $paths) {
            foreach ($paths as $path) {
                $real = realpath($path);
                foreach ($directories as $directory => $name) {
                    if (false !== $real && str_starts_with($real.'/', $directory.'/')) {
                        $packages[$prefix] = $name;
                    }
                }
            }
        }
        uksort($packages, static fn (string $a, string $b): int => \strlen($b) <=> \strlen($a));

        return self::$packages = $packages;
    }

    /**
     * @return array<mixed>
     */
    private static function json(string $file): array
    {
        $data = json_decode((string) file_get_contents($file), true, flags: \JSON_THROW_ON_ERROR);

        return \is_array($data) ? $data : [];
    }

    private static function root(): string
    {
        return \dirname(__DIR__, 4);
    }
}
