<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Tests\Shared\Infrastructure\Markdown;

use App\Shared\Infrastructure\Markdown\CommonMarkMarkdown;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class CommonMarkMarkdownTest extends TestCase
{
    private const string MEMBERSHIP = '0190a8d2-1234-7abc-8def-0123456789ab';

    private CommonMarkMarkdown $markdown;

    protected function setUp(): void
    {
        $this->markdown = new CommonMarkMarkdown();
    }

    public function testRendersTheAllowedFormatting(): void
    {
        $html = $this->markdown->toHtml("Line one\nline **two** *three* ~~four~~ `five`\n\n> quote\n\n```\nblock\n```\n\n---\n\n1. first\n2. second\n\n- item");

        self::assertStringContainsString('<p>Line one<br />', $html);
        self::assertStringContainsString('<strong>two</strong> <em>three</em> <del>four</del> <code>five</code>', $html);
        self::assertStringContainsString("<blockquote>\n<p>quote</p>\n</blockquote>", $html);
        self::assertStringContainsString("<pre><code>block\n</code></pre>", $html);
        self::assertStringContainsString('<hr />', $html);
        self::assertStringContainsString("<ol>\n<li>first</li>", $html);
        self::assertStringContainsString("<ul>\n<li>item</li>\n</ul>", $html);
    }

    public function testRendersNothingForAnEmptySource(): void
    {
        self::assertSame('', $this->markdown->toHtml(" \n "));
    }

    public function testHeadingsStartAtLevelThree(): void
    {
        $html = $this->markdown->toHtml("# One\n\n## Two\n\n### Three\n\n#### Four\n\n###### Six");

        self::assertStringContainsString('<h3>One</h3>', $html);
        self::assertStringContainsString('<h4>Two</h4>', $html);
        self::assertStringContainsString('<h5>Three</h5>', $html);
        self::assertStringContainsString('<h6>Four</h6>', $html);
        self::assertStringContainsString('<h6>Six</h6>', $html);
        self::assertStringNotContainsString('<h1', $html);
        self::assertStringNotContainsString('<h2', $html);
    }

    public function testRendersATaskListAsDisabledCheckboxes(): void
    {
        $html = $this->markdown->toHtml("- [x] done\n- [ ] open");

        self::assertStringContainsString('<li><input checked disabled="disabled" type="checkbox" /> done</li>', $html);
        self::assertStringContainsString('<li><input disabled="disabled" type="checkbox" /> open</li>', $html);
    }

    public function testLinksGetRelAndKeepOnlyHttpHttpsAndMailto(): void
    {
        $html = $this->markdown->toHtml('[a](https://example.com/x) [b](http://example.com) <mail@example.com> www.example.com');

        self::assertStringContainsString('<a href="https://example.com/x" rel="noopener noreferrer nofollow">a</a>', $html);
        self::assertStringContainsString('<a href="http://example.com" rel="noopener noreferrer nofollow">b</a>', $html);
        self::assertStringContainsString('href="mailto:mail&#64;example.com"', $html);
        self::assertStringContainsString('<a href="http://www.example.com" rel="noopener noreferrer nofollow">www.example.com</a>', $html);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function unsafeLinks(): iterable
    {
        yield 'javascript' => ['[x](javascript:alert(1))'];
        yield 'javascript, mixed case and entity' => ['[x](JaVaScRiPt&#58;alert(1))'];
        yield 'javascript, autolink' => ['<javascript:alert(1)>'];
        yield 'vbscript' => ['[x](vbscript:msgbox(1))'];
        yield 'data' => ['[x](data:text/html;base64,PHNjcmlwdD5hbGVydCgxKTwvc2NyaXB0Pg==)'];
        yield 'file' => ['[x](file:///etc/passwd)'];
        yield 'relative' => ['[x](/logout)'];
        yield 'scheme relative' => ['[x](//evil.example/)'];
        yield 'reference' => ["[x][r]\n\n[r]: javascript:alert(1)"];
    }

    #[DataProvider('unsafeLinks')]
    public function testDropsTheHrefOfAnUnsafeLink(string $source): void
    {
        $html = $this->markdown->toHtml($source);

        self::assertStringNotContainsString('href', $html);
        self::assertDoesNotMatchRegularExpression('/="[^"]*(?:javascript|vbscript|data|file):/i', $html);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function rawHtml(): iterable
    {
        yield 'script' => ['<script>alert(1)</script>'];
        yield 'image with onerror' => ['<img src=x onerror=alert(1)>'];
        yield 'svg with onload' => ['<svg onload=alert(1)>'];
        yield 'iframe' => ['<iframe src="https://evil.example"></iframe>'];
        yield 'link' => ['<a href="javascript:alert(1)">x</a>'];
        yield 'style' => ['<style>body{display:none}</style>'];
        yield 'block' => ["<div onclick=\"alert(1)\">\n\n**x**\n\n</div>"];
        yield 'inside emphasis' => ['**<b onmouseover=alert(1)>x</b>**'];
        yield 'inside a list' => ['- <span class="mention">fake</span>'];
    }

    #[DataProvider('rawHtml')]
    public function testEscapesRawHtml(string $source): void
    {
        $html = $this->markdown->toHtml($source);

        self::assertDoesNotMatchRegularExpression('/<(?!\/?(?:p|strong|li|ul|br)\b)[a-z]/i', $html);
        self::assertStringContainsString('&lt;', $html);
    }

    public function testAnAttributeCannotBeSmuggledIntoAnImage(): void
    {
        $html = $this->markdown->toHtml('![x" onerror="alert(1)](/attachments/a.png "t\" onload=\"alert(1)")');

        self::assertStringNotContainsString(' onerror=', $html);
        self::assertStringNotContainsString(' onload=', $html);
        self::assertStringNotContainsString('title', $html);
    }

    public function testKeepsOnlyImagesOfOwnAttachments(): void
    {
        $html = $this->markdown->toHtml('![own](/attachments/0190a8d2/plan.png) ![external](https://evil.example/pixel.png) ![scheme relative](//evil.example/pixel.png) ![data](data:image/png;base64,iVBORw0KGgo=) ![up](/attachments/../logout) ![other](/tasks/1)');

        self::assertStringContainsString('<img src="/attachments/0190a8d2/plan.png" alt="own" />', $html);
        self::assertSame(1, substr_count($html, 'src='));
    }

    public function testRendersAMentionAsAChipWithoutALink(): void
    {
        $html = $this->markdown->toHtml(sprintf('Ask @[Jana Nová](membership:%s) first.', self::MEMBERSHIP));

        self::assertSame('<p>Ask <span class="mention">&#64;Jana Nová</span> first.</p>', trim($html));
    }

    public function testEscapesTheNameOfAMention(): void
    {
        $html = $this->markdown->toHtml(sprintf('@[<img src=x onerror=alert(1)>](membership:%s)', self::MEMBERSHIP));

        self::assertStringContainsString('<span class="mention">&#64;&lt;img src&#61;x onerror&#61;alert(1)&gt;</span>', $html);
        self::assertStringNotContainsString('<img', $html);
    }

    public function testAMentionInCodeStaysCode(): void
    {
        $html = $this->markdown->toHtml(sprintf('`@[Jana](membership:%s)`', self::MEMBERSHIP));

        self::assertStringNotContainsString('mention', $html);
        self::assertStringContainsString('<code>', $html);
    }

    public function testDeepNestingIsCapped(): void
    {
        $html = $this->markdown->toHtml(str_repeat('> ', 500).'deep');

        self::assertLessThanOrEqual(21, substr_count($html, '<blockquote>'));
    }
}
