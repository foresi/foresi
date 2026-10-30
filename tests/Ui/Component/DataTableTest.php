<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Tests\Ui\Component;

use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\UX\TwigComponent\Test\InteractsWithTwigComponents;

final class DataTableTest extends KernelTestCase
{
    use InteractsWithTwigComponents;

    private const array COLUMNS = [
        ['key' => 'name', 'label' => 'Name', 'sortable' => true, 'sort' => 'asc', 'href' => '/contacts?sort=-name'],
        ['key' => 'email', 'label' => 'E-mail', 'sortable' => true, 'href' => '/contacts?sort=email'],
        ['key' => 'balance', 'label' => 'Balance', 'align' => 'right'],
    ];

    /**
     * @return list<array{id: string, href?: string, selected?: bool, cells: array<string, mixed>}>
     */
    private function rows(): array
    {
        return [
            ['id' => 'a1', 'href' => '/contacts/a1', 'cells' => ['name' => 'Example Company', 'email' => 'info@example.com', 'balance' => '1 200']],
            ['id' => 'b2', 'href' => '/contacts/b2', 'cells' => ['name' => 'Second Ltd', 'email' => null, 'balance' => ['text' => 'Overdue', 'tone' => 'red']]],
        ];
    }

    /**
     * @param array<string, mixed>  $props
     * @param array<string, string> $blocks
     */
    private function render(array $props = [], array $blocks = []): string
    {
        return (string) $this->renderTwigComponent('Ui:DataTable', $props + ['columns' => self::COLUMNS, 'rows' => $this->rows(), 'caption' => 'Contacts'], null, $blocks);
    }

    public function testSemanticTableWithCaptionAndScopedHeaders(): void
    {
        $html = $this->render();

        self::assertStringContainsString('<table', $html);
        self::assertMatchesRegularExpression('#<caption[^>]*sr-only[^>]*>Contacts</caption>#', $html);
        self::assertSame(3, substr_count($html, '<th scope="col"'));
        self::assertStringNotContainsString('data-controller', $html);
    }

    public function testSortableHeaderIsALinkWithAriaSort(): void
    {
        $html = $this->render();

        self::assertStringContainsString('aria-sort="ascending"', $html);
        self::assertStringContainsString('aria-sort="none"', $html);
        self::assertStringContainsString('href="/contacts?sort=-name"', $html);
        self::assertStringContainsString('href="/contacts?sort=email"', $html);
        self::assertSame(2, substr_count($html, 'aria-sort='));
    }

    public function testDescendingSort(): void
    {
        $columns = self::COLUMNS;
        $columns[0]['sort'] = 'desc';

        self::assertStringContainsString('aria-sort="descending"', $this->render(['columns' => $columns]));
    }

    public function testSelectableAddsHeaderAndRowCheckboxesWithLabels(): void
    {
        $html = $this->render(['selectable' => true]);

        self::assertStringContainsString('data-controller="table-selection"', $html);
        self::assertStringContainsString('aria-label="Vybrat všechny řádky"', $html);
        self::assertStringContainsString('aria-label="Vybrat řádek Example Company"', $html);
        self::assertStringContainsString('aria-label="Vybrat řádek Second Ltd"', $html);
        self::assertStringContainsString('data-table-selection-target="all"', $html);
        self::assertSame(2, substr_count($html, 'data-table-selection-target="check"'));
        self::assertStringContainsString('value="a1"', $html);
        self::assertStringContainsString('role="region"', $html);
        self::assertStringContainsString('aria-live="polite"', $html);
        self::assertStringContainsString('scroll-mb-24', $html);
    }

    public function testSelectionNameMakesTheRowCheckboxesSubmitTheirIds(): void
    {
        $html = $this->render(['selectable' => true, 'selectionName' => 'ids[]']);

        self::assertSame(2, substr_count($html, 'name="ids[]"'));
        self::assertMatchesRegularExpression('#<input type="checkbox" name="ids\[\]" value="a1"#', $html);
        self::assertStringNotContainsString('name="ids[]"', $this->render(['selectable' => true]));
    }

    public function testPreselectedRowIsChecked(): void
    {
        $rows = $this->rows();
        $rows[0]['selected'] = true;
        $html = $this->render(['selectable' => true, 'rows' => $rows]);

        self::assertSame(1, substr_count($html, ' checked'));
        self::assertStringContainsString('data-selected="true"', $html);
    }

    public function testNotSelectableHasNoCheckboxes(): void
    {
        self::assertStringNotContainsString('type="checkbox"', $this->render());
    }

    public function testBulkActionsAreRenderedInTheBar(): void
    {
        $html = $this->render(['selectable' => true], ['bulk' => '<button>Delete</button>']);

        self::assertStringContainsString('<button>Delete</button>', $html);
    }

    public function testPrimaryLinkIsFirstColumnOnly(): void
    {
        $html = $this->render();

        self::assertSame(2, substr_count($html, 'data-row-link'));
        self::assertStringContainsString('href="/contacts/a1"', $html);
    }

    public function testRowWithoutHrefHasNoLink(): void
    {
        $rows = $this->rows();
        unset($rows[0]['href'], $rows[1]['href']);

        self::assertStringNotContainsString('data-row-link', $this->render(['rows' => $rows]));
    }

    public function testLongValuesAreTruncatedWithTitle(): void
    {
        $long = str_repeat('Long Company Name ', 4);
        $long = substr(trim($long), 0, 60);
        $rows = $this->rows();
        $rows[0]['cells']['name'] = $long;
        $rows[0]['cells']['email'] = str_repeat('a', 50).'@example.com';
        $html = $this->render(['rows' => $rows]);

        self::assertSame(60, strlen($long));
        self::assertStringContainsString('title="'.$long.'"', $html);
        self::assertStringContainsString('title="'.str_repeat('a', 50).'@example.com"', $html);
        self::assertStringContainsString('truncate', $html);
        self::assertStringNotContainsString('title="Second Ltd"', $html);
    }

    public function testTitleBoundaryIsAtTwentyFourCharacters(): void
    {
        $rows = $this->rows();
        $rows[0]['cells']['name'] = str_repeat('a', 24);
        $rows[1]['cells']['name'] = str_repeat('b', 25);
        $html = $this->render(['rows' => $rows]);

        self::assertStringNotContainsString('title="'.str_repeat('a', 24).'"', $html);
        self::assertStringContainsString('title="'.str_repeat('b', 25).'"', $html);
    }

    public function testCellValuesAreEscaped(): void
    {
        $rows = $this->rows();
        $rows[0]['cells']['name'] = '<script>alert(1)</script>';
        $html = $this->render(['rows' => $rows]);

        self::assertStringNotContainsString('<script>', $html);
        self::assertStringContainsString('&lt;script&gt;', $html);
    }

    public function testToneCellRendersBadgeAndNumbersAreTabularRightAligned(): void
    {
        $html = $this->render();

        self::assertStringContainsString('bg-badge-red', $html);
        self::assertStringContainsString('Overdue', $html);
        self::assertStringContainsString('text-right', $html);
        self::assertStringContainsString('num', $html);
    }

    public function testNumericCellsAreRendered(): void
    {
        $rows = $this->rows();
        $rows[0]['cells']['balance'] = 42;

        self::assertStringContainsString('>42<', $this->render(['rows' => $rows]));
    }

    public function testTotalsRow(): void
    {
        $html = $this->render(['totals' => ['balance' => '3 400']]);

        self::assertStringContainsString('<tfoot', $html);
        self::assertStringContainsString('3 400', $html);
        self::assertStringContainsString('Celkem', $html);
    }

    public function testZeroTotalIsRendered(): void
    {
        $html = $this->render(['totals' => ['balance' => 0, 'name' => '']]);

        self::assertMatchesRegularExpression('#<td[^>]*>\s*0\s*</td>#', $html);
    }

    public function testRowsAreClickableOnlyWhenSelectable(): void
    {
        self::assertStringNotContainsString('cursor-pointer', $this->render());
        self::assertStringContainsString('cursor-pointer', $this->render(['selectable' => true]));
    }

    public function testNoTotalsNoFooterRow(): void
    {
        self::assertStringNotContainsString('<tfoot', $this->render());
    }

    public function testEmptyRowsRenderTheEmptySlotInsteadOfATable(): void
    {
        $html = $this->render(['rows' => []], ['empty' => '<p>No contacts yet</p>']);

        self::assertStringContainsString('<p>No contacts yet</p>', $html);
        self::assertStringNotContainsString('<table', $html);
    }

    public function testEmptyRowsWithoutSlotRenderDefaultEmptyState(): void
    {
        $html = $this->render(['rows' => []]);

        self::assertStringContainsString('Zatím tu nic není', $html);
        self::assertStringNotContainsString('<table', $html);
    }

    public function testFooterSlot(): void
    {
        $html = $this->render([], ['footer' => '<span>1-2 of 2</span>']);

        self::assertStringContainsString('<span>1-2 of 2</span>', $html);
        self::assertStringContainsString('border-t', $html);
    }

    public function testNoFooterWithoutSlot(): void
    {
        self::assertStringNotContainsString('data-table-footer', $this->render());
    }

    /**
     * @return iterable<string, array{array<string, mixed>}>
     */
    public static function invalid(): iterable
    {
        yield 'column without key' => [['columns' => [['label' => 'A']]]];
        yield 'column without label' => [['columns' => [['key' => 'a']]]];
        yield 'column with bad align' => [['columns' => [['key' => 'a', 'label' => 'A', 'align' => 'middle']]]];
        yield 'sortable without href' => [['columns' => [['key' => 'a', 'label' => 'A', 'sortable' => true]]]];
        yield 'sortable with unsafe href' => [['columns' => [['key' => 'a', 'label' => 'A', 'sortable' => true, 'href' => 'javascript:alert(1)']]]];
        yield 'bad sort direction' => [['columns' => [['key' => 'a', 'label' => 'A', 'sortable' => true, 'href' => '/x', 'sort' => 'up']]]];
        yield 'row without cells' => [['rows' => [['id' => 'a']]]];
        yield 'row with unsafe href' => [['rows' => [['id' => 'a', 'href' => 'javascript:alert(1)', 'cells' => []]]]];
        yield 'selectable row without id' => [['selectable' => true, 'rows' => [['cells' => ['name' => 'x']]]]];
        yield 'cell with unknown tone' => [['rows' => [['cells' => ['name' => ['text' => 'x', 'tone' => 'pink']]]]]];
        yield 'cell with object value' => [['rows' => [['cells' => ['name' => new \stdClass()]]]]];
        yield 'array cell without text' => [['rows' => [['cells' => ['name' => ['tone' => 'red']]]]]];
    }

    /**
     * @param array<string, mixed> $props
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('invalid')]
    public function testInvalidInputThrows(array $props): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->mountTwigComponent('Ui:DataTable', $props + ['columns' => self::COLUMNS, 'rows' => [], 'caption' => 'x']);
    }
}
