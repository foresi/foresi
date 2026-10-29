<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Tests\Ui\Component;

use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\UX\TwigComponent\Test\InteractsWithTwigComponents;

final class FieldTest extends KernelTestCase
{
    use InteractsWithTwigComponents;

    public function testRendersLabelHelpErrorAndControl(): void
    {
        $html = (string) $this->renderTwigComponent('Ui:Field', ['id' => 'email', 'label' => 'E-mail', 'help' => 'Work address', 'error' => 'Invalid', 'required' => true], '<input id="email">');

        self::assertStringContainsString('<label for="email"', $html);
        self::assertStringContainsString('E-mail', $html);
        self::assertStringContainsString('<input id="email">', $html);
        self::assertStringContainsString('id="email-help"', $html);
        self::assertStringContainsString('id="email-error"', $html);
        self::assertStringContainsString('*', $html);
        self::assertLessThan(strpos($html, '<input'), strpos($html, '<label'));
    }

    public function testMinimalFieldHasNoHelpNorError(): void
    {
        $html = (string) $this->renderTwigComponent('Ui:Field', ['id' => 'x', 'label' => 'X'], 'C');

        self::assertStringNotContainsString('-help', $html);
        self::assertStringNotContainsString('-error', $html);
        self::assertStringNotContainsString('*', $html);
    }

    public function testInlineLabelFollowsControl(): void
    {
        $html = (string) $this->renderTwigComponent('Ui:Field', ['id' => 'x', 'label' => 'X', 'inline' => true], '<input id="x">');

        self::assertMatchesRegularExpression('/<label[^>]*>\\s*<input id="x">.*<\/label>/s', $html);
    }

    public function testMissingIdThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->mountTwigComponent('Ui:Field', ['label' => 'X']);
    }

    public function testErrorIsAnAlert(): void
    {
        self::assertStringContainsString('id="x-error" role="alert"', (string) $this->renderTwigComponent('Ui:Field', ['id' => 'x', 'label' => 'X', 'error' => 'Bad'], 'C'));
    }
}
