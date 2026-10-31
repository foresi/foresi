<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Tests\Ui\Component;

use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\UX\TwigComponent\Test\InteractsWithTwigComponents;

final class ButtonTest extends KernelTestCase
{
    use InteractsWithTwigComponents;

    public function testRendersButtonWithVariantAndIcon(): void
    {
        $html = (string) $this->renderTwigComponent('Ui:Button', ['variant' => 'primary', 'icon' => 'plus'], 'Add');

        self::assertStringStartsWith('<button', $html);
        self::assertStringContainsString('type="button"', $html);
        self::assertStringContainsString('bg-green', $html);
        self::assertStringContainsString('h-control', $html);
        self::assertStringContainsString('focus-visible:outline-2', $html);
        self::assertStringContainsString('<svg', $html);
        self::assertStringContainsString('aria-hidden="true"', $html);
        self::assertStringContainsString('Add', $html);
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function variants(): iterable
    {
        yield 'primary' => ['primary', 'bg-green'];
        yield 'secondary' => ['secondary', 'bg-white'];
        yield 'ghost' => ['ghost', 'bg-transparent'];
        yield 'danger' => ['danger', 'text-badge-red-text'];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('variants')]
    public function testVariantClasses(string $variant, string $expected): void
    {
        self::assertStringContainsString($expected, (string) $this->renderTwigComponent('Ui:Button', ['variant' => $variant], 'X'));
    }

    public function testSmallSize(): void
    {
        $html = (string) $this->renderTwigComponent('Ui:Button', ['size' => 'sm'], 'X');

        self::assertStringContainsString('h-control-sm', $html);
        self::assertDoesNotMatchRegularExpression('/\bh-control(?![\w-])/', $html);
    }

    public function testHrefRendersAnchor(): void
    {
        $html = (string) $this->renderTwigComponent('Ui:Button', ['href' => '/contacts'], 'Go');

        self::assertStringStartsWith('<a', $html);
        self::assertStringContainsString('href="/contacts"', $html);
        self::assertStringNotContainsString('type="button"', $html);
    }

    public function testSubmitTypeAndExtraAttributes(): void
    {
        $html = (string) $this->renderTwigComponent('Ui:Button', ['type' => 'submit', 'disabled' => true], 'Save');

        self::assertStringContainsString('type="submit"', $html);
        self::assertStringContainsString('disabled', $html);
    }

    public function testUnknownVariantThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->mountTwigComponent('Ui:Button', ['variant' => 'huge']);
    }

    public function testUnknownSizeThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->mountTwigComponent('Ui:Button', ['size' => 'xl']);
    }

    public function testPressedState(): void
    {
        $html = (string) $this->renderTwigComponent('Ui:Button', ['pressed' => true], 'X');

        self::assertStringContainsString('aria-pressed="true"', $html);
        self::assertStringContainsString('bg-green-tint', $html);
        self::assertStringNotContainsString('aria-pressed', (string) $this->renderTwigComponent('Ui:Button', [], 'X'));
    }

    public function testPressedLinkThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->mountTwigComponent('Ui:Button', ['pressed' => true, 'href' => '/x']);
    }

    public function testUnknownIconThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->mountTwigComponent('Ui:Button', ['icon' => 'rocket']);
    }

    public function testJavascriptHrefThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->mountTwigComponent('Ui:Button', ['href' => 'javascript:alert(1)']);
    }
}
