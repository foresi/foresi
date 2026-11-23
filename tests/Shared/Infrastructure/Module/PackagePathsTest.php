<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\Shared\Infrastructure\Module;

use PHPUnit\Framework\TestCase;

/**
 * Each module is installed as its own Composer package, in vendor/foresi/<name>
 * with a lowercase directory and without the src/<Module> level of the
 * repository. A path written from __DIR__ therefore resolves only while it stays
 * inside the module's own directory; one that climbs into another module works in
 * the repository and breaks in every installation. Another module's files are
 * reached through a constant or service of that module instead.
 */
final class PackagePathsTest extends TestCase
{
    public function testNoModuleReachesOutOfItsOwnPackageFromDir(): void
    {
        $src = \dirname(__DIR__, 4).'/src';
        $escapes = [];
        foreach ((array) glob($src.'/*/composer.json') as $composer) {
            $module = \dirname((string) $composer);
            $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($module, \FilesystemIterator::SKIP_DOTS));
            foreach ($files as $file) {
                if (!$file instanceof \SplFileInfo || 'php' !== $file->getExtension()) {
                    continue;
                }
                $code = (string) file_get_contents($file->getPathname());
                if (\in_array(preg_match_all("/__DIR__\\s*\\.\\s*'([^']*)'/", $code, $matches), [0, false], true)) {
                    continue;
                }
                foreach ($matches[1] as $relative) {
                    $target = $this->normalise($file->getPath().$relative);
                    if (!str_starts_with($target.'/', $module.'/')) {
                        $escapes[] = substr($file->getPathname(), \strlen($src) + 1).": __DIR__.'".$relative."'";
                    }
                }
            }
        }

        self::assertSame([], $escapes, 'These paths leave their module and break when it is installed as a package.');
    }

    private function normalise(string $path): string
    {
        $parts = [];
        foreach (explode('/', $path) as $part) {
            if ('..' === $part) {
                array_pop($parts);
            } elseif ('.' !== $part && '' !== $part) {
                $parts[] = $part;
            }
        }

        return '/'.implode('/', $parts);
    }
}
