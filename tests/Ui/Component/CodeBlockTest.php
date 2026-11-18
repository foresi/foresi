<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\Ui\Component;

use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\UX\TwigComponent\Test\InteractsWithTwigComponents;

final class CodeBlockTest extends KernelTestCase
{
    use InteractsWithTwigComponents;

    public function testJsonIsColouredAndKeepsItsText(): void
    {
        $code = "{\n    \"url\": \"https://app.example.com/mcp\"\n}";
        $crawler = new Crawler((string) $this->renderTwigComponent('Ui:CodeBlock', ['id' => 'cfg', 'label' => 'Cursor', 'code' => $code, 'language' => 'json']));

        $pre = $crawler->filter('pre#cfg');
        self::assertSame($code, $pre->text(normalizeWhitespace: false));
        self::assertSame('"url"', $pre->filter('.hl-keyword')->text());
        self::assertSame('cfg-label', $pre->attr('aria-labelledby'));
        self::assertSame('0', $pre->attr('tabindex'));
        self::assertSame('Cursor', $crawler->filter('figcaption#cfg-label')->text());
        self::assertCount(1, $crawler->filter('button[data-action="copy#copy"]'));
    }

    public function testTextIsEscapedAndNotColoured(): void
    {
        $html = (string) $this->renderTwigComponent('Ui:CodeBlock', ['id' => 'h', 'label' => 'Header', 'code' => 'Authorization: Bearer <token>']);

        self::assertStringContainsString('Authorization: Bearer &lt;token&gt;</code>', $html);
        self::assertStringNotContainsString('hl-', $html);
    }

    public function testShellIsEscaped(): void
    {
        $html = (string) $this->renderTwigComponent('Ui:CodeBlock', ['id' => 's', 'label' => 'Claude Code', 'code' => 'claude mcp add --header "Authorization: Bearer <token>"', 'language' => 'bash']);

        self::assertStringContainsString('&lt;token&gt;', $html);
        self::assertStringNotContainsString('<token>', $html);
    }

    public function testUnknownLanguageThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->mountTwigComponent('Ui:CodeBlock', ['id' => 'x', 'label' => 'X', 'language' => 'php']);
    }

    public function testAnIdIsRequired(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->mountTwigComponent('Ui:CodeBlock', ['label' => 'X', 'code' => 'x']);
    }

    public function testALabelIsRequired(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->mountTwigComponent('Ui:CodeBlock', ['id' => 'x', 'code' => 'x']);
    }
}
