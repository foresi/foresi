<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Tests\Ui\Component;

use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\UX\TwigComponent\Test\InteractsWithTwigComponents;

final class TextInputTest extends KernelTestCase
{
    use InteractsWithTwigComponents;

    public function testRendersLabelledRequiredInput(): void
    {
        $html = (string) $this->renderTwigComponent('Ui:TextInput', ['name' => 'email', 'label' => 'E-mail', 'type' => 'email', 'value' => 'testing@example.com', 'required' => true]);

        self::assertStringContainsString('<label for="email"', $html);
        self::assertStringContainsString('type="email"', $html);
        self::assertStringContainsString('id="email"', $html);
        self::assertStringContainsString('name="email"', $html);
        self::assertStringContainsString('value="testing@example.com"', $html);
        self::assertStringContainsString(' required', $html);
        self::assertStringContainsString('h-control', $html);
        self::assertStringContainsString('rounded-control', $html);
        self::assertStringContainsString('focus-visible:outline-2', $html);
        self::assertStringNotContainsString('aria-invalid', $html);
        self::assertStringNotContainsString('aria-describedby', $html);
        self::assertDoesNotMatchRegularExpression('/ disabled[ >]/', $html);
    }

    public function testErrorAndHelpAreAnnounced(): void
    {
        $html = (string) $this->renderTwigComponent('Ui:TextInput', ['name' => 'email', 'label' => 'E-mail', 'help' => 'Hint', 'error' => 'Invalid e-mail']);

        self::assertStringContainsString('aria-invalid="true"', $html);
        self::assertStringContainsString('aria-describedby="email-help email-error"', $html);
        self::assertStringContainsString('id="email-error"', $html);
        self::assertStringContainsString('border-badge-red-text', $html);
    }

    public function testErrorOnlyDescribedByError(): void
    {
        $html = (string) $this->renderTwigComponent('Ui:TextInput', ['name' => 'a', 'label' => 'A', 'error' => 'Bad']);

        self::assertStringContainsString('aria-describedby="a-error"', $html);
    }

    public function testExplicitIdWinsAndDisabled(): void
    {
        $html = (string) $this->renderTwigComponent('Ui:TextInput', ['name' => 'n', 'id' => 'custom', 'label' => 'N', 'disabled' => true]);

        self::assertStringContainsString('id="custom"', $html);
        self::assertStringContainsString('for="custom"', $html);
        self::assertMatchesRegularExpression('/ disabled[ >]/', $html);
    }

    public function testValueIsEscaped(): void
    {
        $html = (string) $this->renderTwigComponent('Ui:TextInput', ['name' => 'n', 'label' => 'N', 'value' => '"><script>']);

        self::assertStringNotContainsString('<script>', $html);
    }

    public function testNameOrIdRequired(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->mountTwigComponent('Ui:TextInput', ['label' => 'N']);
    }

    public function testLabelRequired(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->mountTwigComponent('Ui:TextInput', ['name' => 'n']);
    }
}
