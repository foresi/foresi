<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Tests\Ui\Component;

use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\UX\TwigComponent\Test\InteractsWithTwigComponents;

final class TimelineTest extends KernelTestCase
{
    use InteractsWithTwigComponents;

    public function testRendersOrderedListWithTimeAndIcon(): void
    {
        $html = (string) $this->renderTwigComponent('Ui:Timeline', ['items' => [
            ['time' => '10:42', 'icon' => 'pencil', 'text' => 'Contact edited', 'datetime' => '2026-10-29T10:42'],
            ['time' => 'Yesterday', 'icon' => 'plus', 'text' => 'Contact created'],
        ]]);

        self::assertStringContainsString('<ol', $html);
        self::assertSame(2, substr_count($html, '<li'));
        self::assertStringContainsString('<time datetime="2026-10-29T10:42"', $html);
        self::assertStringContainsString('Contact created', $html);
        self::assertSame(2, substr_count($html, '<svg'));
    }

    public function testInvalidIconThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->mountTwigComponent('Ui:Timeline', ['items' => [['time' => 't', 'icon' => 'nope', 'text' => 'x']]]);
    }

    public function testMissingTextThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->mountTwigComponent('Ui:Timeline', ['items' => [['time' => 't', 'icon' => 'plus']]]);
    }

    public function testMissingTimeThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->mountTwigComponent('Ui:Timeline', ['items' => [['icon' => 'plus', 'text' => 'x']]]);
    }

    public function testTextIsEscaped(): void
    {
        $html = (string) $this->renderTwigComponent('Ui:Timeline', ['items' => [['time' => 't', 'icon' => 'plus', 'text' => '<u>x</u>']]]);

        self::assertStringNotContainsString('<u>', $html);
    }
}
