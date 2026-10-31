<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Tests\Party\Infrastructure\Http;

use App\Party\Infrastructure\Http\WebsiteLink;
use App\Ui\Component\Style;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(WebsiteLink::class)]
final class WebsiteLinkTest extends TestCase
{
    /**
     * @return iterable<string, array{string, string|null}>
     */
    public static function websites(): iterable
    {
        yield 'https URL' => ['https://www.example.com', 'https://www.example.com'];
        yield 'http URL with a path' => ['http://example.com/contact?x=1#top', 'http://example.com/contact?x=1#top'];
        yield 'URL with a port' => ['https://example.com:8443/', 'https://example.com:8443/'];
        yield 'upper-case scheme' => ['HTTPS://EXAMPLE.COM', 'HTTPS://EXAMPLE.COM'];
        yield 'bare domain' => ['example.com', 'https://example.com'];
        yield 'bare domain with www and a path' => ['www.example.com/page', 'https://www.example.com/page'];
        yield 'javascript' => ['javascript:alert(1)', null];
        yield 'ftp' => ['ftp://example.com', null];
        yield 'protocol-relative' => ['//example.com', null];
        yield 'a word' => ['example', null];
        yield 'spaces' => ['example .com', null];
        yield 'space in the path' => ['https://example.com/a b', null];
        yield 'backslash' => ['https://example.com\evil', null];
        yield 'credentials' => ['https://user@example.com', null];
        yield 'one-letter top-level domain' => ['example.c', null];
        yield 'trailing newline' => ["https://example.com\n", null];
        yield 'empty' => ['', null];
    }

    #[DataProvider('websites')]
    public function testOnlyWebAddressesBecomeLinks(string $website, ?string $link): void
    {
        self::assertSame($link, WebsiteLink::of($website));
        // Every link it makes passes the href rules of the components.
        Style::href($link);
    }
}
