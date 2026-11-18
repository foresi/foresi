<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\Ui\Component;

use Foresi\Ui\Component\ColumnPicker;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\UX\TwigComponent\Test\InteractsWithTwigComponents;

final class ColumnPickerTest extends KernelTestCase
{
    use InteractsWithTwigComponents;

    private const array PROPS = [
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
    ];

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

        // Email; the locked number and Kind, which is to add, do not move.
        self::assertSame(1, substr_count($html, 'data-column-picker-up'));
        self::assertSame(1, substr_count($html, 'data-column-picker-down'));
        self::assertStringContainsString('aria-label="Posunout Email nahoru"', $html);
        self::assertStringContainsString('aria-live="polite"', $html);
        self::assertStringContainsString('data-column-picker-moved-value="{label} je teď na pozici {position} z {total}."', $html);
    }

    public function testTheHiddenColumnsAreToAddAfterTheShownOnes(): void
    {
        $html = $this->render();

        self::assertStringContainsString('Přidat sloupec', $html);
        self::assertMatchesRegularExpression('#data-key="email".*data-column-picker-add.*data-column-picker-target="option" data-key="kind"#s', $html);
        self::assertMatchesRegularExpression('#<input type="checkbox" id="cols-kind" name="cols\[\]" value="kind" class#', $html);
    }

    public function testWithNothingToAddThereIsNoAddPart(): void
    {
        $html = $this->render(['columns' => [['key' => 'number', 'label' => 'Number', 'visible' => true, 'locked' => true]]]);

        self::assertStringNotContainsString('data-column-picker-add', $html);
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

        // Shown or to add, the search goes over all of them.
        $columns = array_map(static fn (int $i): array => ['key' => 'c'.$i, 'label' => 'C'.$i, 'visible' => 1 === $i % 2, 'locked' => false], range(1, 11));
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
     * @return iterable<string, array{array<string, mixed>}>
     */
    public static function invalidProps(): iterable
    {
        yield 'no id' => [['id' => '']];
        yield 'no action' => [['action' => '']];
        yield 'no reset link' => [['resetHref' => '']];
        yield 'an unsafe action' => [['action' => 'javascript:alert(1)']];
        yield 'an unsafe reset link' => [['resetHref' => 'javascript:alert(1)']];
    }

    /**
     * @param array<string, mixed> $props
     */
    #[DataProvider('invalidProps')]
    public function testItRefusesWhatItCannotRender(array $props): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->mount($props);
    }

    public function testAColumnIsHiddenAndMovableUnlessItSaysOtherwiseAndALockedOneIsAlwaysShown(): void
    {
        $picker = $this->mount(['columns' => [
            ['key' => 'due.date', 'label' => 'Due'],
            ['key' => 'name', 'label' => 'Name', 'visible' => false, 'locked' => true],
        ]]);

        self::assertSame([['key' => 'name', 'label' => 'Name', 'visible' => true, 'locked' => true, 'id' => 'cols-name']], $picker->shown());
        self::assertSame([['key' => 'due.date', 'label' => 'Due', 'visible' => false, 'locked' => false, 'id' => 'cols-due-date']], $picker->addable());
    }

    public function testTenColumnsNeedNoSearch(): void
    {
        $columns = array_map(static fn (int $i): array => ['key' => 'c'.$i, 'label' => 'C'.$i], range(1, 10));

        self::assertFalse($this->mount(['columns' => $columns])->searchable());
    }

    public function testTheFormKeepsTheListsStateButNotItsWorkingDisplayOrPage(): void
    {
        $picker = $this->mount(['keep' => ['view' => 'paid', 'q' => '', 'archived' => true, 'draft' => false, 'limit' => 5, 'cols' => 'a', 'density' => 'dense', 'page' => '2', 'sort' => null]]);

        self::assertSame(['view' => 'paid', 'archived' => '1', 'draft' => '0', 'limit' => '5'], $picker->hidden());
    }

    /**
     * @param array<string, mixed> $props
     */
    private function mount(array $props = []): ColumnPicker
    {
        $component = $this->mountTwigComponent('Ui:ColumnPicker', $props + self::PROPS);
        self::assertInstanceOf(ColumnPicker::class, $component);

        return $component;
    }

    /**
     * @param array<string, mixed> $props
     */
    private function render(array $props = []): string
    {
        return (string) $this->renderTwigComponent('Ui:ColumnPicker', $props + self::PROPS);
    }
}
