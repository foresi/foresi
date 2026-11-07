<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Tests\Ui\Component;

use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\UX\TwigComponent\Test\InteractsWithTwigComponents;

final class SelectTest extends KernelTestCase
{
    use InteractsWithTwigComponents;

    public function testRendersOptionsWithSelection(): void
    {
        $html = (string) $this->renderTwigComponent('Ui:Select', ['name' => 'country', 'label' => 'Country', 'value' => 'SK', 'options' => ['CZ' => 'Czechia', 'SK' => 'Slovakia'], 'error' => 'Pick one']);

        self::assertStringContainsString('<select', $html);
        self::assertStringContainsString('<option value="CZ">Czechia', $html);
        self::assertStringContainsString('<option value="SK" selected>Slovakia', $html);
        self::assertStringContainsString('aria-invalid="true"', $html);
        self::assertStringContainsString('aria-describedby="country-error"', $html);
        self::assertStringContainsString('h-control', $html);
    }

    public function testNumericKeysMatchStringValue(): void
    {
        $html = (string) $this->renderTwigComponent('Ui:Select', ['name' => 'n', 'label' => 'N', 'value' => '2', 'options' => [1 => 'One', 2 => 'Two']]);

        self::assertStringContainsString('<option value="2" selected>', $html);
        self::assertStringNotContainsString('<option value="1" selected>', $html);
    }

    public function testHasLockedChevronAndNoNativeArrow(): void
    {
        $html = (string) $this->renderTwigComponent('Ui:Select', ['name' => 'n', 'label' => 'N']);

        self::assertStringContainsString('appearance-none', $html);
        self::assertStringContainsString('pointer-events-none', $html);
        self::assertStringContainsString('<svg', $html);
    }

    public function testAGroupOfOptionsRendersAsAnOptgroup(): void
    {
        $html = (string) $this->renderTwigComponent('Ui:Select', ['name' => 'month', 'label' => 'Month', 'value' => '2025-12', 'options' => ['' => 'All months', 2026 => ['2026-01' => 'January 2026'], 2025 => ['2025-12' => 'December 2025']]]);

        self::assertStringContainsString('<option value="">All months</option>', $html);
        self::assertMatchesRegularExpression('~<optgroup label="2026">\s*<option value="2026-01">January 2026</option>\s*</optgroup>~', $html);
        self::assertMatchesRegularExpression('~<optgroup label="2025">\s*<option value="2025-12" selected>December 2025</option>\s*</optgroup>~', $html);
    }
}
