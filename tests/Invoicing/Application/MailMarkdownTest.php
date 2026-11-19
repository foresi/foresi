<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\Invoicing\Application;

use Foresi\Invoicing\Application\LinkedMailBody;
use Foresi\Invoicing\Application\MailMarkdown;
use Foresi\Invoicing\Application\MailText;
use Foresi\Shared\Infrastructure\Markdown\CommonMarkMarkdown;
use PHPUnit\Framework\TestCase;

final class MailMarkdownTest extends TestCase
{
    public function testInlineMarksAreEscapedEverywhere(): void
    {
        self::assertSame('a\\*b\\* \\_c\\_ \\`d\\` \\[e\\] \\<f\\> \\~g\\~ \\\\h', MailMarkdown::escape('a*b* _c_ `d` [e] <f> ~g~ \\h', false));
        self::assertSame('AT&T \\&amp; \\&#35;', MailMarkdown::escape('AT&T &amp; &#35;', false));
        self::assertSame('https\\://x.example.com www\\.example.com', MailMarkdown::escape('https://x.example.com www.example.com', false));
    }

    public function testABlockMarkIsEscapedOnlyWhereALineStarts(): void
    {
        // Inside a sentence the first line keeps its marks; every later line of the value starts a line.
        self::assertSame("# one\n  \\# two\n\\- three\n4\\. four\n5\\) five", MailMarkdown::escape("# one\n  # two\n- three\n4. four\n5) five", false));
        self::assertSame("\\> quote\n\\+ plus\n\\= under", MailMarkdown::escape("> quote\n+ plus\n= under", true));
        self::assertSame('2026 is a year', MailMarkdown::escape('2026 is a year', true));
    }

    public function testThePlainPartDropsOnlyTheEscapingBackslashes(): void
    {
        self::assertSame('a*b* [c] C:\\Users 1. 2', MailMarkdown::plain('a\\*b\\* \\[c\\] C:\\Users 1\\. 2'));
    }

    public function testLineEndsOfTheTemplateAndTheValuesAreNormalised(): void
    {
        self::assertSame("Hello\n1\\. a\n2\\. b\n{link}", new MailText()->renderBody("Hello\r\n{x}\r{link}", ['x' => "1. a\r\n2. b"]));
    }

    public function testALinkWithSpacesOrBracketsStaysOneLink(): void
    {
        $body = new LinkedMailBody('Open {link} or [here]({link})', "https://example.com/d/a b<c>\n", new CommonMarkMarkdown());

        self::assertSame(2, substr_count($body->html(), 'href="https://example.com/d/a%20b%3Cc%3E"'));
    }
}
