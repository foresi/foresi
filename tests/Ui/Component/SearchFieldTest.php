<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Tests\Ui\Component;

use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\UX\TwigComponent\Test\InteractsWithTwigComponents;

final class SearchFieldTest extends KernelTestCase
{
    use InteractsWithTwigComponents;

    public function testRendersASearchInputLabelledForAssistiveTechnology(): void
    {
        $html = (string) $this->renderTwigComponent('Ui:SearchField', ['name' => 'q', 'label' => 'Search contacts', 'value' => 'example', 'placeholder' => 'Name']);

        self::assertStringContainsString('type="search"', $html);
        self::assertStringContainsString('name="q"', $html);
        self::assertStringContainsString('id="q"', $html);
        self::assertStringContainsString('value="example"', $html);
        self::assertStringContainsString('aria-label="Search contacts"', $html);
        self::assertStringContainsString('placeholder="Name"', $html);
        self::assertStringContainsString('h-control', $html);
        self::assertStringContainsString('focus-visible:outline-2', $html);
        self::assertStringNotContainsString('<label', $html);
    }

    public function testExtraAttributesAndClassesReachTheInput(): void
    {
        $html = (string) $this->renderTwigComponent('Ui:SearchField', ['name' => 'q', 'label' => 'Search', 'attributes' => ['maxlength' => '100', 'class' => 'flex-1']]);

        self::assertMatchesRegularExpression('/<input[^>]*maxlength="100"/', $html);
        self::assertStringContainsString('flex-1', $html);
        self::assertStringNotContainsString('placeholder', $html);
    }

    public function testValueIsEscaped(): void
    {
        $html = (string) $this->renderTwigComponent('Ui:SearchField', ['name' => 'q', 'label' => 'S', 'value' => '"><script>']);

        self::assertStringNotContainsString('<script>', $html);
    }

    public function testLabelRequired(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->mountTwigComponent('Ui:SearchField', ['name' => 'q']);
    }
}
