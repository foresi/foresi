<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\Ui\Component;

use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\UX\TwigComponent\Test\InteractsWithTwigComponents;

final class ColumnPickerTest extends KernelTestCase
{
    use InteractsWithTwigComponents;

    public function testItIsAButtonThatOpensASmallSheetWithAGetFormToTheList(): void
    {
        $html = $this->render();

        self::assertStringContainsString('data-action="column-picker#open"', $html);
        self::assertStringContainsString('aria-haspopup="dialog"', $html);
        self::assertStringContainsString('aria-controls="cols-sheet"', $html);
        self::assertStringContainsString('w-[400px]', $html);
        self::assertMatchesRegularExpression('#<form method="get" action="/list"#', $html);
        self::assertStringContainsString('<input type="hidden" name="view" value="paid">', $html);
        self::assertStringNotContainsString('name="page"', $html);
        self::assertStringContainsString('data-column-picker-reset="true" href="/list?view=paid"', $html);
    }

    public function testEachColumnHasACheckboxInOrderAndTheLockedOneLeadsAndCannotBeHidden(): void
    {
        $html = $this->render();

        self::assertMatchesRegularExpression('#data-key="number".*data-key="email".*data-key="kind"#s', $html);
        self::assertMatchesRegularExpression('#<input type="hidden" name="cols\[\]" value="number"#', $html);
        self::assertMatchesRegularExpression('#id="cols-number" checked disabled#', $html);
        self::assertMatchesRegularExpression('#name="cols\[\]" value="email" checked#', $html);
        self::assertDoesNotMatchRegularExpression('#name="cols\[\]" value="kind" checked#', $html);
    }

    public function testEveryMovableRowHasMoveButtonsAndThereIsALiveRegion(): void
    {
        $html = $this->render();

        self::assertSame(2, substr_count($html, 'data-column-picker-up'));
        self::assertSame(2, substr_count($html, 'data-column-picker-down'));
        self::assertStringContainsString('aria-label="Posunout Email nahoru"', $html);
        self::assertStringContainsString('aria-live="polite"', $html);
        self::assertStringContainsString('data-column-picker-moved-value="{label} je teď na pozici {position} z {total}."', $html);
    }

    public function testTheDensityIsARadioGroupWithTheCurrentOneChecked(): void
    {
        $html = $this->render(['density' => 'dense']);

        self::assertStringContainsString('name="density" value="comfortable" class', $html);
        self::assertStringContainsString('name="density" value="dense" checked', $html);
    }

    public function testSearchShowsOnlyPastTenColumns(): void
    {
        self::assertStringNotContainsString('data-column-picker-target="search"', $this->render());

        $columns = array_map(static fn (int $i): array => ['key' => 'c'.$i, 'label' => 'C'.$i, 'visible' => true, 'locked' => false], range(1, 11));
        self::assertStringContainsString('data-column-picker-target="search"', $this->render(['columns' => $columns]));
    }

    public function testAModifiedListMarksTheButton(): void
    {
        self::assertStringNotContainsString(', změněno', $this->render());
        self::assertStringContainsString('<span class="sr-only">, změněno</span>', $this->render(['modified' => true]));
    }

    public function testAnUnknownDensityIsRefused(): void
    {
        $this->expectException(\Throwable::class);
        $this->render(['density' => 'huge']);
    }

    /**
     * @param array<string, mixed> $props
     */
    private function render(array $props = []): string
    {
        return (string) $this->renderTwigComponent('Ui:ColumnPicker', $props + [
            'id' => 'cols',
            'action' => '/list',
            'keep' => ['view' => 'paid', 'page' => '2', 'q' => null],
            'columns' => [
                ['key' => 'email', 'label' => 'Email', 'visible' => true, 'locked' => false],
                ['key' => 'number', 'label' => 'Number', 'visible' => true, 'locked' => true],
                ['key' => 'kind', 'label' => 'Kind', 'visible' => false, 'locked' => false],
            ],
            'density' => 'comfortable',
            'resetHref' => '/list?view=paid',
        ]);
    }
}
