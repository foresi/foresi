<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Tests\Ui\Component;

use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\UX\TwigComponent\Test\InteractsWithTwigComponents;

final class CardTest extends KernelTestCase
{
    use InteractsWithTwigComponents;

    public function testRendersTitleIconAndBody(): void
    {
        $html = (string) $this->renderTwigComponent('Ui:Card', ['title' => 'Details', 'icon' => 'building-2'], '<p>Body</p>');

        self::assertStringContainsString('rounded-panel', $html);
        self::assertStringContainsString('<h3', $html);
        self::assertStringContainsString('Details', $html);
        self::assertStringContainsString('<svg', $html);
        self::assertStringContainsString('<p>Body</p>', $html);
    }

    public function testWithoutTitleHasNoHeading(): void
    {
        $html = (string) $this->renderTwigComponent('Ui:Card', [], 'Body');

        self::assertStringNotContainsString('<h3', $html);
        self::assertStringContainsString('Body', $html);
    }

    public function testTitleWithoutIconHasNoSvg(): void
    {
        self::assertStringNotContainsString('<svg', (string) $this->renderTwigComponent('Ui:Card', ['title' => 'T'], 'B'));
    }

    public function testUnknownIconThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->mountTwigComponent('Ui:Card', ['icon' => 'rocket']);
    }
}
