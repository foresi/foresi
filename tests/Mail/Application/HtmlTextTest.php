<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\Mail\Application;

use Foresi\Mail\Application\HtmlText;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The text of an HTML mail body: what a reader sees, one line per block, with
 * nothing followed or fetched.
 */
final class HtmlTextTest extends TestCase
{
    #[DataProvider('bodies')]
    public function testItGivesTheTextAReaderSees(string $html, string $text): void
    {
        self::assertSame($text, HtmlText::of($html));
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function bodies(): iterable
    {
        yield 'nothing' => ['', ''];
        yield 'only blanks' => ["  \n ", ''];
        yield 'blocks are lines' => ['<span>a<div>b</div>c</span>', "a\nb\nc"];
        yield 'a break ends the line' => ['a<br>b', "a\nb"];
        yield 'inline elements run on' => ['<b>a</b><i>b</i>', 'ab'];
        yield 'each line is trimmed and its blanks are one space' => ["<p>  a \t b  </p>\n<p> </p><p>c</p>", "a b\nc"];
        yield 'a no-break space is a space' => ["a\u{A0}\u{A0}b\u{202F}c", 'a b c'];
        yield 'a line break in a text is a space' => ["a\rb\r\nc\nd", 'a b c d'];
        yield 'scripts and styles are not text' => ['<style>p {}</style>x<SCRIPT>bad()</SCRIPT>y', 'xy'];
        yield 'the title and the head are not text' => ['<html><head><title>Title</title></head><body>Body</body></html>', 'Body'];
        yield 'a comment is not text' => ['a<!-- hidden -->b', 'ab'];
        yield 'the first character beyond ASCII' => ["a\u{80}b", "a\u{80}b"];
        yield 'the last character there is' => ["a\u{10FFFF}b", "a\u{10FFFF}b"];
        yield 'a body in UTF-8 whatever it declares' => ['<meta charset="iso-8859-1"><p>naïve café – déjà vu</p>', 'naïve café – déjà vu'];
    }

    public function testNoParserErrorIsLeftBehindOrTheModeChanged(): void
    {
        libxml_use_internal_errors(true);
        libxml_clear_errors();

        try {
            self::assertSame('a b', HtmlText::of('<p><b>a</i> <u>b</p></x>'));
            self::assertTrue(libxml_use_internal_errors(), 'The mode that was set is kept.');
            self::assertSame([], libxml_get_errors());

            libxml_use_internal_errors(false);
            HtmlText::of('<p><b>a</i></p></x>');
            self::assertFalse(libxml_use_internal_errors());
            self::assertSame([], libxml_get_errors());
        } finally {
            libxml_use_internal_errors(false);
            libxml_clear_errors();
        }
    }
}
