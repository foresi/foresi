<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\Shared\Contract;

use Foresi\Shared\Infrastructure\Kernel\ForesiKernel;
use Foresi\Shared\Infrastructure\Module\ModuleBundle;
use PHPUnit\Framework\TestCase;

/**
 * The module API is every Contract namespace plus the few classes outside one
 * that are marked @api (ADR 0044). This list makes a new @api mark a decision of
 * its own: adding one means adding it here.
 */
final class PublicSurfaceTest extends TestCase
{
    public function testOnlyTheseClassesOutsideAContractAreMarkedApi(): void
    {
        self::assertSame([ForesiKernel::class, ModuleBundle::class], $this->markedApi());
    }

    /**
     * @return list<string>
     */
    private function markedApi(): array
    {
        $root = \dirname(__DIR__, 3).'/src';
        $marked = [];
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS | \FilesystemIterator::CURRENT_AS_PATHNAME));
        foreach ($files as $path) {
            if (!\is_string($path) || !str_ends_with($path, '.php') || str_contains($path, '/Contract/')) {
                continue;
            }
            $code = (string) file_get_contents($path);
            if (1 !== preg_match('/^namespace ([^;]+);/m', $code, $namespace)) {
                continue;
            }
            preg_match_all('~/\*\*(?:(?!\*/).)*?\n\s*\*\s*@api\b(?:(?!\*/).)*\*/\s*(?:#\[[^\n]*\]\s*)*(?:(?:final|abstract|readonly)\s+)*(?:class|interface|trait|enum)\s+(\w+)~s', $code, $matches);
            foreach ($matches[1] as $name) {
                $marked[] = $namespace[1].'\\'.$name;
            }
        }
        sort($marked);

        return $marked;
    }
}
