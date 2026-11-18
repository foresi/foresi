<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\Ui\Component;

use Foresi\Ui\Component\Tabs;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\UX\TwigComponent\Test\InteractsWithTwigComponents;

final class TabsTest extends KernelTestCase
{
    use InteractsWithTwigComponents;

    public function testActiveItemHasAriaCurrent(): void
    {
        $html = (string) $this->renderTwigComponent('Ui:Tabs', [
            'label' => 'Views',
            'items' => [
                ['label' => 'All', 'href' => '/a', 'count' => 12, 'active' => true],
                ['label' => 'Open', 'href' => '/b'],
            ],
        ]);

        self::assertStringContainsString('<nav', $html);
        self::assertStringContainsString('aria-label="Views"', $html);
        self::assertSame(1, substr_count($html, 'aria-current="page"'));
        self::assertStringContainsString('href="/a" aria-current="page"', $html);
        self::assertStringContainsString('border-green text-ink', $html);
        self::assertStringContainsString('>12<', $html);
        self::assertStringContainsString('Open', $html);
        self::assertStringContainsString('focus-visible:outline-2', $html);
    }

    public function testZeroCountIsShownAndNullCountIsNot(): void
    {
        $html = (string) $this->renderTwigComponent('Ui:Tabs', ['items' => [
            ['label' => 'A', 'href' => '/a', 'count' => 0],
            ['label' => 'B', 'href' => '/b', 'count' => null],
        ]]);

        self::assertSame(1, substr_count($html, 'class="num'));
        self::assertStringContainsString('>0<', $html);
        self::assertStringNotContainsString('aria-label', $html);
    }

    public function testInvalidItemThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->mountTwigComponent('Ui:Tabs', ['items' => [['label' => 'A']]]);
    }

    public function testNonStringLabelThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->mountTwigComponent('Ui:Tabs', ['items' => [['label' => 1, 'href' => '/a']]]);
    }

    public function testNonStringHrefThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->mountTwigComponent('Ui:Tabs', ['items' => [['label' => 'A', 'href' => 1]]]);
    }

    public function testUnsafeHrefThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->mountTwigComponent('Ui:Tabs', ['items' => [['label' => 'A', 'href' => 'javascript:alert(1)']]]);
    }

    public function testTheMoreViewsMenuNeedsItsLabel(): void
    {
        foreach ([null, ''] as $label) {
            try {
                $this->mountTwigComponent('Ui:Tabs', ['items' => [['label' => 'A', 'href' => '/a']], 'more' => [['label' => 'B', 'href' => '/b']], 'moreLabel' => $label]);
                self::fail('The menu is refused.');
            } catch (\InvalidArgumentException) {
            }
        }
        $tabs = $this->mountTwigComponent('Ui:Tabs', ['items' => [['label' => 'A', 'href' => '/a']], 'more' => [['label' => 'B', 'href' => '/b']], 'moreLabel' => 'More views']);
        self::assertInstanceOf(Tabs::class, $tabs);
    }

    public function testEveryItemIsChecked(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->mountTwigComponent('Ui:Tabs', ['items' => [['label' => 'A', 'href' => '/a'], ['label' => 'B']]]);
    }

    public function testAnInvalidItemOfTheMenuThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->mountTwigComponent('Ui:Tabs', ['items' => [['label' => 'A', 'href' => '/a']], 'more' => [['label' => 'B']], 'moreLabel' => 'More views']);
    }

    public function testTheMenuIsActiveWhenItHoldsTheViewShown(): void
    {
        $tabs = new Tabs();
        $tabs->items = [['label' => 'A', 'href' => '/a', 'active' => true]];
        self::assertFalse($tabs->moreActive());

        $tabs->more = [['label' => 'B', 'href' => '/b'], ['label' => 'C', 'href' => '/c', 'active' => 1]];
        self::assertFalse($tabs->moreActive());

        $tabs->more[] = ['label' => 'D', 'href' => '/d', 'active' => true];
        self::assertTrue($tabs->moreActive());
    }
}
