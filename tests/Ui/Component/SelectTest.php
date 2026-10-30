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
}
