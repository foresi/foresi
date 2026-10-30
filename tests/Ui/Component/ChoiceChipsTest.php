<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Tests\Ui\Component;

use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\UX\TwigComponent\Test\InteractsWithTwigComponents;

final class ChoiceChipsTest extends KernelTestCase
{
    use InteractsWithTwigComponents;

    public function testRendersOneCheckboxPerOptionInsideALabelledFieldset(): void
    {
        $html = (string) $this->renderTwigComponent('Ui:ChoiceChips', [
            'name' => 'roles[]',
            'id' => 'roles',
            'legend' => 'Roles',
            'options' => ['customer' => 'Customer', 'supplier' => 'Supplier'],
            'values' => ['supplier'],
        ]);

        self::assertStringContainsString('<fieldset', $html);
        self::assertStringContainsString('<legend', $html);
        self::assertSame(2, substr_count($html, 'type="checkbox"'));
        self::assertStringContainsString('id="roles-customer" name="roles[]" value="customer" class="sr-only"', $html);
        self::assertStringContainsString('id="roles-supplier" name="roles[]" value="supplier" checked', $html);
        self::assertStringContainsString('has-[:focus-visible]:outline-2', $html);
        self::assertStringNotContainsString('aria-describedby', $html);
    }

    public function testErrorAndHelpAreLinkedToTheFieldset(): void
    {
        $html = (string) $this->renderTwigComponent('Ui:ChoiceChips', [
            'name' => 'roles[]',
            'id' => 'roles',
            'legend' => 'Roles',
            'help' => 'Pick all that apply.',
            'error' => 'Choose at least one role.',
            'options' => ['customer' => 'Customer'],
        ]);

        self::assertStringContainsString('aria-describedby="roles-help roles-error"', $html);
        self::assertStringContainsString('id="roles-error"', $html);
        self::assertStringContainsString('aria-invalid="true"', $html);
    }

    public function testNeedsOptionsAndALegend(): void
    {
        $this->expectException(\Throwable::class);
        $this->renderTwigComponent('Ui:ChoiceChips', ['name' => 'roles[]', 'id' => 'roles', 'legend' => 'Roles', 'options' => []]);
    }

    public function testRejectsAnEmptyLabel(): void
    {
        $this->expectException(\Throwable::class);
        $this->renderTwigComponent('Ui:ChoiceChips', ['name' => 'roles[]', 'id' => 'roles', 'legend' => 'Roles', 'options' => ['customer' => '']]);
    }
}
