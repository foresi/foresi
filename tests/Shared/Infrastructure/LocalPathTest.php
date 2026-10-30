<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Tests\Shared\Infrastructure;

use App\Shared\Infrastructure\Http\LocalPath;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class LocalPathTest extends TestCase
{
    #[DataProvider('accepted')]
    public function testSameOriginPathsAreKept(string $path): void
    {
        self::assertSame($path, LocalPath::resolve($path, '/fallback'));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function accepted(): iterable
    {
        yield 'root' => ['/'];
        yield 'path' => ['/contacts'];
        yield 'query' => ['/contacts?page=2&q=a%2Fb'];
        yield 'fragment' => ['/contacts#top'];
        yield 'colon in query' => ['/search?q=http://example.com'];
        yield 'encoded slashes stay a path' => ['/%2F%2Fevil'];
    }

    #[DataProvider('rejected')]
    public function testEverythingElseFallsBack(?string $candidate): void
    {
        self::assertSame('/fallback', LocalPath::resolve($candidate, '/fallback'));
    }

    /**
     * @return iterable<string, array{?string}>
     */
    public static function rejected(): iterable
    {
        yield 'null' => [null];
        yield 'empty' => [''];
        yield 'absolute url' => ['https://evil.example/'];
        yield 'scheme relative' => ['//evil.example/'];
        yield 'backslash trick' => ['/\\evil.example'];
        yield 'backslash anywhere' => ['/a\\b'];
        yield 'javascript' => ['javascript:alert(1)'];
        yield 'relative' => ['contacts'];
        yield 'leading space' => [' /contacts'];
        yield 'tab' => ["/a\tb"];
        yield 'newline' => ["/a\nb"];
        yield 'nul' => ["/a\0b"];
        yield 'delete' => ["/a\x7Fb"];
    }
}
