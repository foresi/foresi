<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Tests\Ui\Component;

use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\UX\TwigComponent\Test\InteractsWithTwigComponents;

final class CheckboxTest extends KernelTestCase
{
    use InteractsWithTwigComponents;

    public function testRendersCheckedCheckboxWithLabelAfter(): void
    {
        $html = (string) $this->renderTwigComponent('Ui:Checkbox', ['name' => 'agree', 'label' => 'I agree', 'checked' => true, 'help' => 'Required by law']);

        self::assertStringContainsString('type="checkbox"', $html);
        self::assertStringContainsString(' checked', $html);
        self::assertStringContainsString('value="1"', $html);
        self::assertStringContainsString('size-4', $html);
        self::assertStringContainsString('aria-describedby="agree-help"', $html);
        self::assertGreaterThan(strpos($html, '<input'), strpos($html, '<label'));
    }

    public function testUncheckedWithErrorAndCustomValue(): void
    {
        $html = (string) $this->renderTwigComponent('Ui:Checkbox', ['name' => 'agree', 'label' => 'I agree', 'value' => 'yes', 'error' => 'Required', 'required' => true]);

        self::assertStringNotContainsString(' checked', $html);
        self::assertStringContainsString('value="yes"', $html);
        self::assertStringContainsString('aria-invalid="true"', $html);
        self::assertStringContainsString(' required', $html);
        self::assertStringContainsString('focus-visible:outline-2', $html);
    }
}
