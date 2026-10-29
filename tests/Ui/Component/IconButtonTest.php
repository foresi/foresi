<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Tests\Ui\Component;

use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\UX\TwigComponent\Test\InteractsWithTwigComponents;

final class IconButtonTest extends KernelTestCase
{
    use InteractsWithTwigComponents;

    public function testRendersAccessibleName(): void
    {
        $html = (string) $this->renderTwigComponent('Ui:IconButton', ['icon' => 'search', 'label' => 'Search']);

        self::assertStringStartsWith('<button', $html);
        self::assertStringContainsString('aria-label="Search"', $html);
        self::assertStringContainsString('w-control', $html);
        self::assertStringContainsString('<svg', $html);
    }

    public function testSmallAndLink(): void
    {
        $html = (string) $this->renderTwigComponent('Ui:IconButton', ['icon' => 'x', 'label' => 'Close', 'size' => 'sm', 'href' => '/x']);

        self::assertStringStartsWith('<a', $html);
        self::assertStringContainsString('w-7', $html);
        self::assertStringContainsString('aria-label="Close"', $html);
    }

    public function testMissingLabelThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->mountTwigComponent('Ui:IconButton', ['icon' => 'x']);
    }

    public function testMissingIconThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->mountTwigComponent('Ui:IconButton', ['label' => 'Close']);
    }

    public function testUnknownVariantThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->mountTwigComponent('Ui:IconButton', ['icon' => 'x', 'label' => 'Close', 'variant' => 'nope']);
    }

    public function testBlankLabelThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->mountTwigComponent('Ui:IconButton', ['icon' => 'x', 'label' => '  ']);
    }
}
