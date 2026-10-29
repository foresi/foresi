<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Tests\Ui\Component;

use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\UX\TwigComponent\Test\InteractsWithTwigComponents;

final class PageHeaderTest extends KernelTestCase
{
    use InteractsWithTwigComponents;

    public function testTitleIsH1AndCrumbsFormLabelledNavigation(): void
    {
        $html = (string) $this->renderTwigComponent('Ui:PageHeader', [
            'title' => 'Example Company',
            'crumbs' => [['label' => 'Contacts', 'href' => '/contacts'], ['label' => 'Detail']],
        ]);

        self::assertStringContainsString('<h1', $html);
        self::assertStringContainsString('Example Company', $html);
        self::assertStringContainsString('<nav', $html);
        self::assertStringContainsString('aria-label="Drobečková navigace"', $html);
        self::assertStringContainsString('href="/contacts"', $html);
        self::assertSame(1, substr_count($html, 'aria-current="page"'));
    }

    public function testOnlyTheLastCrumbIsCurrent(): void
    {
        $html = (string) $this->renderTwigComponent('Ui:PageHeader', ['title' => 'T', 'crumbs' => [['label' => 'A'], ['label' => 'B', 'href' => '/b'], ['label' => 'C']]]);

        self::assertSame(1, substr_count($html, 'aria-current="page"'));
        self::assertStringContainsString('aria-current="page">C<', $html);
    }

    public function testNoCrumbsNoNav(): void
    {
        self::assertStringNotContainsString('<nav', (string) $this->renderTwigComponent('Ui:PageHeader', ['title' => 'T']));
    }

    public function testBadgeAndActions(): void
    {
        $html = (string) $this->renderTwigComponent('Ui:PageHeader', [
            'title' => 'T',
            'badge' => ['label' => 'Active', 'tone' => 'green'],
        ], '<a href="/edit">Edit</a>');

        self::assertStringContainsString('bg-badge-green', $html);
        self::assertStringContainsString('Active', $html);
        self::assertStringContainsString('<a href="/edit">Edit</a>', $html);
    }

    public function testNoBadgeByDefault(): void
    {
        self::assertStringNotContainsString('bg-badge-', (string) $this->renderTwigComponent('Ui:PageHeader', ['title' => 'T']));
    }

    public function testLongTitleIsTruncatedWithTitleAttribute(): void
    {
        $title = str_repeat('Long Company Name ', 4);
        $html = (string) $this->renderTwigComponent('Ui:PageHeader', ['title' => $title]);

        self::assertStringContainsString('title="'.$title.'"', $html);
        self::assertStringContainsString('truncate', $html);
    }

    public function testTitleIsRequired(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->mountTwigComponent('Ui:PageHeader', []);
    }

    public function testUnsafeCrumbHrefThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->mountTwigComponent('Ui:PageHeader', ['title' => 'T', 'crumbs' => [['label' => 'x', 'href' => 'javascript:alert(1)']]]);
    }

    public function testCrumbWithoutLabelThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->mountTwigComponent('Ui:PageHeader', ['title' => 'T', 'crumbs' => [['href' => '/x']]]);
    }

    public function testInvalidBadgeThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->mountTwigComponent('Ui:PageHeader', ['title' => 'T', 'badge' => ['label' => 'x', 'tone' => 'pink']]);
    }
}
