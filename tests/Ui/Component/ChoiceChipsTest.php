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

    public function testTheFirstChipOfAnInvalidGroupTakesTheFocusAndEveryChipNamesTheError(): void
    {
        $crawler = $this->renderTwigComponent('Ui:ChoiceChips', [
            'name' => 'roles[]', 'id' => 'roles', 'legend' => 'Roles',
            'options' => ['customer' => 'Customer', 'supplier' => 'Supplier'],
            'error' => 'Choose a role.',
        ])->crawler();

        $chips = $crawler->filter('input');
        self::assertSame(['roles-error', 'roles-error'], $chips->each(static fn ($chip): ?string => $chip->attr('aria-describedby')));
        self::assertNotNull($chips->eq(0)->attr('autofocus'));
        self::assertNull($chips->eq(1)->attr('autofocus'));
        // A second invalid group on the same page does not take it.
        $other = $this->renderTwigComponent('Ui:ChoiceChips', ['name' => 'x[]', 'id' => 'x', 'legend' => 'X', 'options' => ['a' => 'A'], 'error' => 'Bad'])->crawler();
        self::assertCount(0, $other->filter('[autofocus]'));
    }

    public function testAValidGroupTakesNoFocus(): void
    {
        $crawler = $this->renderTwigComponent('Ui:ChoiceChips', ['name' => 'roles[]', 'id' => 'roles', 'legend' => 'Roles', 'options' => ['customer' => 'Customer']])->crawler();

        self::assertCount(0, $crawler->filter('[autofocus], [aria-describedby="roles-error"]'));
    }
}
