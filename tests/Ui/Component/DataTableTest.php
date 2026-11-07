<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Tests\Ui\Component;

use App\Ui\Avatar;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\DomCrawler\Crawler;
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
     * @return list<array<string, mixed>>
     */
    private function rowsWithActions(): array
    {
        return [[
            'id' => 'a1',
            'href' => '/invoices/a1/edit',
            'plain' => true,
            'secondary' => 'Example Company · 1 Nov',
            'cells' => ['name' => '20260001', 'email' => ['text' => '1 Nov', 'tone' => 'red', 'hint' => 'Overdue'], 'balance' => '1 200'],
            'actions' => [
                ['label' => 'Edit', 'href' => '/invoices/a1/edit', 'method' => 'GET', 'icon' => 'pencil', 'plain' => true],
                ['label' => 'Restore', 'href' => '/invoices/a1/restore', 'method' => 'POST', 'icon' => 'archive-restore', 'fields' => ['return' => 'tab=trash']],
                ['label' => 'Mark', 'href' => '/invoices/a1/mark', 'method' => 'POST', 'confirm' => 'Sure?'],
                ['label' => 'History', 'href' => '/invoices/a1/history', 'method' => 'GET'],
            ],
        ]];
    }

    /**
     * @param list<array<string, mixed>> $actions
     *
     * @return list<array<string, mixed>>
     */
    private function rowsWith(array $actions): array
    {
        return [['id' => 'a1', 'href' => '/invoices/a1/edit', 'cells' => ['name' => '20260001', 'email' => '1 Nov', 'balance' => '1 200'], 'actions' => $actions]];
    }

    public function testRowActionsShowInTheRowAndInTheMenu(): void
    {
        $html = $this->render(['rows' => $this->rowsWithActions(), 'actionToken' => 'tok']);

        self::assertStringContainsString('data-row-actions', $html);
        self::assertMatchesRegularExpression('~<a[^>]*href="/invoices/a1/edit"[^>]*aria-label="Edit"|<a[^>]*aria-label="Edit"[^>]*href="/invoices/a1/edit"~', $html);
        self::assertStringContainsString('name="_token" value="tok"', $html);
        self::assertStringContainsString('name="return" value="tab=trash"', $html);
        self::assertStringContainsString('data-turbo-confirm="Sure?"', $html);
        self::assertStringContainsString('role="menuitem" tabindex="-1" data-phone-only data-turbo="false"', $html);
        self::assertStringContainsString('data-dropdown-float-value="true"', $html);
        self::assertSame(4, substr_count($html, 'role="menuitem"'), 'Everything is in the menu of a phone.');
    }

    public function testAConfirmationNamesItsOutcomeAndADialogActionOpensInPlace(): void
    {
        $crawler = new Crawler($this->render(['rows' => $this->rowsWith([
            ['label' => 'Mark', 'href' => '/invoices/a1/mark', 'method' => 'POST', 'confirm' => ['title' => 'Mark 20260001 as delivered?', 'text' => 'It can no longer be deleted.', 'accept' => 'Mark as delivered', 'tone' => 'danger']],
            ['label' => 'Send', 'href' => '/invoices/a1/send', 'method' => 'GET', 'dialog' => true],
            ['label' => 'History', 'href' => '/invoices/a1/history', 'method' => 'GET'],
        ]), 'actionToken' => 'tok']));

        $form = $crawler->filter('form[action="/invoices/a1/mark"]')->first();
        self::assertSame('Mark 20260001 as delivered?', $form->attr('data-turbo-confirm'));
        self::assertSame('It can no longer be deleted.', $form->attr('data-confirm-text'));
        self::assertSame('Mark as delivered', $form->attr('data-confirm-accept'));
        self::assertSame('danger', $form->attr('data-confirm-tone'));
        self::assertNull($form->attr('data-confirm-cancel'));
        // The link is the page without JavaScript; the dialog controller opens it in place.
        $send = $crawler->filter('a[role="menuitem"][href="/invoices/a1/send"]');
        self::assertNotNull($send->attr('data-dialog'));
        self::assertSame('dialog', $send->attr('aria-haspopup'));
        self::assertNull($crawler->filter('a[role="menuitem"][href="/invoices/a1/history"]')->attr('data-dialog'));
    }

    public function testAnIconActionOpensItsDialogInPlaceToo(): void
    {
        $crawler = new Crawler($this->render(['rows' => $this->rowsWith([
            ['label' => 'Send again', 'href' => '/invoices/a1/send', 'method' => 'GET', 'icon' => 'send', 'dialog' => true],
        ])]));

        $icon = $crawler->filter('a[aria-label="Send again"]');
        self::assertNotNull($icon->attr('data-dialog'));
        self::assertSame('dialog', $icon->attr('aria-haspopup'));
    }

    public function testAConfirmationWithoutTitleThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->mountTwigComponent('Ui:DataTable', ['columns' => self::COLUMNS, 'rows' => $this->rowsWith([
            ['label' => 'Mark', 'href' => '/invoices/a1/mark', 'method' => 'POST', 'confirm' => ['text' => 'No question.']],
        ])]);
    }

    public function testARowAlertShowsUnderItsColumnAndOnAPhoneUnderTheFirstCell(): void
    {
        $rows = [['id' => 'a1', 'href' => '/contacts/a1', 'cells' => ['name' => 'Example Company', 'email' => 'info@example.com', 'balance' => '1 200'], 'alert' => ['text' => 'Failed: no address.', 'column' => 'email', 'href' => '/contacts/a1/edit', 'action' => 'Edit the contact']]];

        $crawler = new Crawler($this->render(['rows' => $rows]));

        $cells = $crawler->filter('tbody td');
        $alert = $cells->eq(1)->filter('[data-row-alert]');
        self::assertStringContainsString('Failed: no address.', $alert->text());
        self::assertSame('/contacts/a1/edit', $alert->filter('a')->attr('href'));
        self::assertStringContainsString('max-sm:hidden', (string) $alert->attr('class'));
        self::assertSame('info@example.com', $cells->eq(1)->filter('[title], span')->first()->text(), 'the cell keeps its own text');
        $phone = $cells->eq(0)->filter('[data-row-alert-phone]');
        self::assertStringContainsString('Edit the contact', $phone->text());
        self::assertStringContainsString('sm:hidden', (string) $phone->attr('class'));
    }

    public function testARowAlertUnderAnUnknownColumnThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->mountTwigComponent('Ui:DataTable', ['columns' => self::COLUMNS, 'rows' => [['id' => 'a1', 'cells' => ['name' => 'x'], 'alert' => ['text' => 'Failed.', 'column' => 'nowhere']]]]);
    }

    public function testATableWithoutActionsHasNoColumnForThem(): void
    {
        $html = $this->render();

        self::assertStringNotContainsString('data-row-actions', $html);
        self::assertStringNotContainsString('role="menu"', $html);
    }

    public function testAPlainRowLinkLeavesTurboAndAPhoneGetsItsLine(): void
    {
        $html = $this->render(['rows' => $this->rowsWithActions()]);

        self::assertMatchesRegularExpression('~href="/invoices/a1/edit" data-row-link data-turbo="false"~', $html);
        self::assertStringContainsString('data-row-secondary>Example Company · 1 Nov<', $html);
        self::assertStringContainsString('<span class="sr-only"> · Overdue</span>', $html);
    }

    public function testAColumnCanStayOffAPhone(): void
    {
        $columns = [['key' => 'name', 'label' => 'Name'], ['key' => 'email', 'label' => 'E-mail', 'phone' => false]];

        $html = $this->render(['columns' => $columns, 'rows' => $this->rows()]);

        self::assertSame(3, substr_count($html, 'max-sm:hidden'), 'The header and both cells of the column.');
    }

    public function testATruncatingColumnSharesTheRoomLeftAndAlwaysHasATitle(): void
    {
        $columns = [['key' => 'name', 'label' => 'Name', 'truncate' => true], ['key' => 'email', 'label' => 'E-mail', 'truncate' => true], ['key' => 'balance', 'label' => 'Balance', 'align' => 'right']];

        $html = $this->render(['columns' => $columns, 'rows' => $this->rows()]);

        self::assertSame(6, substr_count($html, 'sm:max-w-0 sm:w-1/2'), 'The headers and all cells of both columns.');
        self::assertStringContainsString('title="Second Ltd"', $html, 'A short text can be cut short too.');
        self::assertStringContainsString('title="info@example.com"', $html);
        self::assertStringNotContainsString('title="1 200"', $html, 'Numbers keep their width.');
        self::assertStringNotContainsString('max-w-[32ch]', $html, 'A truncating column is cut by its share, not a fixed cap.');
    }

    public function testAOneTruncatingColumnTakesAllTheRoomLeft(): void
    {
        $columns = [['key' => 'name', 'label' => 'Name', 'truncate' => true], ['key' => 'email', 'label' => 'E-mail']];

        $html = $this->render(['columns' => $columns, 'rows' => $this->rows()]);

        self::assertSame(3, substr_count($html, 'sm:max-w-0 sm:w-full'));
        self::assertStringNotContainsString('title="Second Ltd"', $this->render());
    }

    public function testAWideColumnIsLeftOutOnANarrowerScreen(): void
    {
        $columns = [['key' => 'name', 'label' => 'Name'], ['key' => 'email', 'label' => 'E-mail', 'wide' => true]];

        $html = $this->render(['columns' => $columns, 'rows' => $this->rows()]);

        self::assertSame(3, substr_count($html, 'max-xl:hidden'), 'The header and both cells of the column.');
        self::assertStringNotContainsString('max-xl:hidden', $this->render());
    }

    public function testALinksCellLinksEachOfItsValues(): void
    {
        $columns = [['key' => 'name', 'label' => 'Name'], ['key' => 'paid', 'label' => 'Paid with']];
        $rows = [['id' => 'a1', 'cells' => ['name' => 'Payment', 'paid' => ['links' => [['text' => '20260001', 'href' => '/invoices/1/edit'], ['text' => '20260002', 'href' => '/invoices/2/edit']], 'plain' => true]]]];

        $html = $this->render(['columns' => $columns, 'rows' => $rows]);

        self::assertMatchesRegularExpression('~<a href="/invoices/1/edit" data-turbo="false"[^>]*>20260001</a>, <a href="/invoices/2/edit" data-turbo="false"[^>]*>20260002</a>~', $html);
        self::assertStringNotContainsString('data-row-link', $html, 'The links are the cell, not a link of the row.');
    }

    public function testALinksCellStaysInTurboUnlessItIsPlain(): void
    {
        $rows = [['id' => 'a1', 'cells' => ['paid' => ['links' => [['text' => '20260001', 'href' => '/invoices/1/edit']]]]]];

        $html = $this->render(['columns' => [['key' => 'paid', 'label' => 'Paid with']], 'rows' => $rows]);

        self::assertStringContainsString('href="/invoices/1/edit"', $html);
        self::assertStringNotContainsString('data-turbo="false"', $html);
    }

    public function testALinksCellNeedsAtLeastOneLink(): void
    {
        $this->expectExceptionMessageIsOrContains('A links cell needs a list of links.');

        $this->render(['columns' => [['key' => 'paid', 'label' => 'Paid']], 'rows' => [['cells' => ['paid' => ['links' => []]]]]]);
    }

    public function testALinkOfACellNeedsASafeHref(): void
    {
        $this->expectExceptionMessageIsOrContains('Unsupported URL in href.');

        $this->render(['columns' => [['key' => 'paid', 'label' => 'Paid']], 'rows' => [['cells' => ['paid' => ['links' => [['text' => 'x', 'href' => 'javascript:alert(1)']]]]]]]);
    }

    public function testARowActionGoesByGetOrPost(): void
    {
        $this->expectExceptionMessageIsOrContains('A row action goes by GET or POST.');

        $this->render(['rows' => [['cells' => [], 'actions' => [['label' => 'Go', 'href' => '/x', 'method' => 'DELETE']]]]]);
    }

    public function testARowActionNeedsALabel(): void
    {
        $this->expectException(\Throwable::class);

        $this->render(['rows' => [['cells' => [], 'actions' => [['href' => '/x', 'method' => 'GET']]]]]);
    }

    public function testRowActionsAreAList(): void
    {
        $this->expectExceptionMessageIsOrContains('Row actions must be a list.');

        $this->render(['rows' => [['cells' => [], 'actions' => 'edit']]]);
    }

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

    public function testAnIconCellShowsItsLabelAndExplainsItInATooltip(): void
    {
        $rows = $this->rows();
        $rows[0]['cells']['name'] = ['text' => 'Read', 'icon' => 'circle-check', 'tone' => 'green', 'hint' => 'The customer opened it'];

        $html = $this->render(['rows' => $rows]);

        self::assertMatchesRegularExpression('#<span class="inline-flex items-center gap-1.5" title="The customer opened it" data-icon-cell="circle-check"><svg[^>]*class="size-4 shrink-0 text-green"[^>]*aria-hidden="true"#', $html);
        self::assertStringContainsString('<span>Read</span>', $html);
    }

    public function testAnIconCellWithoutAHintHasNoTooltip(): void
    {
        $rows = $this->rows();
        $rows[0]['cells']['name'] = ['text' => 'Queued', 'icon' => 'send'];

        $html = $this->render(['rows' => $rows]);

        self::assertMatchesRegularExpression('#<span class="inline-flex items-center gap-1.5" data-icon-cell="send"><svg[^>]*class="size-4 shrink-0 text-muted"#', $html);
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
        yield 'icon cell without text' => [['rows' => [['cells' => ['name' => ['icon' => 'send']]]]]];
        yield 'icon cell with an icon that is not a name' => [['rows' => [['cells' => ['name' => ['text' => 'x', 'icon' => ['send']]]]]]];
        yield 'icon cell with an unknown icon' => [['rows' => [['cells' => ['name' => ['text' => 'x', 'icon' => 'rocket']]]]]];
        yield 'icon cell with an unknown tone' => [['rows' => [['cells' => ['name' => ['text' => 'x', 'icon' => 'send', 'tone' => 'pink']]]]]];
        yield 'row alert without a text' => [['rows' => [['cells' => ['name' => 'x'], 'alert' => ['column' => 'name']]]]];
        yield 'row alert with a text that is not a string' => [['rows' => [['cells' => ['name' => 'x'], 'alert' => ['text' => 5, 'column' => 'name']]]]];
        yield 'row alert with an empty text' => [['rows' => [['cells' => ['name' => 'x'], 'alert' => ['text' => '', 'column' => 'name']]]]];
        yield 'row alert with an unsafe href' => [['rows' => [['cells' => ['name' => 'x'], 'alert' => ['text' => 'Failed.', 'column' => 'name', 'href' => 'javascript:alert(1)', 'action' => 'Fix']]]]];
        yield 'row alert with an href that is not a string' => [['rows' => [['cells' => ['name' => 'x'], 'alert' => ['text' => 'Failed.', 'column' => 'name', 'href' => 5, 'action' => 'Fix']]]]];
        yield 'row alert with an action that is not a string' => [['rows' => [['cells' => ['name' => 'x'], 'alert' => ['text' => 'Failed.', 'column' => 'name', 'href' => '/fix', 'action' => 5]]]]];
        yield 'row alert with an empty action' => [['rows' => [['cells' => ['name' => 'x'], 'alert' => ['text' => 'Failed.', 'column' => 'name', 'href' => '/fix', 'action' => '']]]]];
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

    public function testAPersonCellShowsTheInitialsOnAColourTheSeedAlwaysPicks(): void
    {
        $rows = [
            ['id' => 'a1', 'href' => '/people/a1', 'cells' => ['name' => ['avatar' => 'user-1', 'text' => 'Jana Nováková Example'], 'email' => 'jana@example.com', 'balance' => '']],
            ['id' => 'b2', 'cells' => ['name' => ['avatar' => 'user-2', 'text' => 'Šimon'], 'email' => null, 'balance' => '']],
        ];

        $crawler = new Crawler($this->render(['rows' => $rows]));

        $first = $crawler->filter('[data-avatar-cell]')->eq(0);
        self::assertSame('JN', trim($first->filter('[aria-hidden="true"]')->text()));
        self::assertStringContainsString(Avatar::tone('user-1'), (string) $first->filter('[aria-hidden="true"]')->attr('class'));
        self::assertSame('/people/a1', $first->filter('a')->attr('href'));
        self::assertSame('Š', trim($crawler->filter('[data-avatar-cell]')->eq(1)->filter('[aria-hidden="true"]')->text()));
        self::assertSame('Šimon', $crawler->filter('[data-avatar-cell]')->eq(1)->filter('span span')->last()->text());
        self::assertSame(Avatar::tone('user-1'), Avatar::tone('user-1'));
        self::assertContains(Avatar::tone('user-2'), Avatar::TONES);
    }

    #[\PHPUnit\Framework\Attributes\DoesNotPerformAssertions]
    public function testAPersonCellNeedsANameAndASeed(): void
    {
        foreach ([['avatar' => 'user-1', 'text' => ''], ['avatar' => 1, 'text' => 'Jana'], ['avatar' => 'user-1', 'text' => 5], ['avatar' => 'user-1']] as $cell) {
            try {
                $this->mountTwigComponent('Ui:DataTable', ['columns' => self::COLUMNS, 'rows' => [['id' => 'a1', 'cells' => ['name' => $cell]]]]);
                self::fail('A person cell without a name or seed was accepted.');
            } catch (\InvalidArgumentException) {
            }
        }
    }

    public function testABadgesCellShowsEachBadgeWithItsTone(): void
    {
        $rows = [['id' => 'a1', 'cells' => ['name' => 'Task', 'email' => ['badges' => [['label' => 'Bug', 'tone' => 'red'], ['label' => 'Design']]], 'balance' => '']]];

        $crawler = new Crawler($this->render(['rows' => $rows]));

        $badges = $crawler->filter('[data-badges] > *');
        self::assertSame(['Bug', 'Design'], $badges->each(static fn (Crawler $badge): string => trim($badge->text())));
        self::assertStringContainsString('bg-badge-red', (string) $badges->eq(0)->attr('class'));
        self::assertStringContainsString('bg-badge-gray', (string) $badges->eq(1)->attr('class'));
    }

    #[\PHPUnit\Framework\Attributes\DoesNotPerformAssertions]
    public function testABadgesCellNeedsBadgesWithLabelsAndKnownTones(): void
    {
        foreach ([['badges' => []], ['badges' => [['tone' => 'red']]], ['badges' => [['label' => 'Bug', 'tone' => 'blue']]], ['badges' => 'Bug']] as $cell) {
            try {
                $this->mountTwigComponent('Ui:DataTable', ['columns' => self::COLUMNS, 'rows' => [['id' => 'a1', 'cells' => ['name' => $cell]]]]);
                self::fail('A badges cell without valid badges was accepted.');
            } catch (\InvalidArgumentException) {
            }
        }
    }

    public function testAChildRowIsIndentedUnderTheRowBeforeIt(): void
    {
        $rows = [
            ['id' => 'a1', 'href' => '/tasks/a1', 'cells' => ['name' => 'Task', 'email' => '', 'balance' => '']],
            ['id' => 'b2', 'href' => '/tasks/b2', 'child' => true, 'cells' => ['name' => 'Subtask', 'email' => '', 'balance' => '']],
        ];

        $crawler = new Crawler($this->render(['rows' => $rows]));

        self::assertCount(1, $crawler->filter('tbody tr[data-row-child]'));
        $child = $crawler->filter('tbody tr')->eq(1);
        self::assertNotNull($child->attr('data-row-child'));
        self::assertStringContainsString('text-small', (string) $child->attr('class'));
        self::assertStringContainsString('pl-10', (string) $child->filter('td')->eq(0)->attr('class'));
        self::assertStringNotContainsString('pl-10', (string) $crawler->filter('tbody tr')->eq(0)->filter('td')->eq(0)->attr('class'));
    }
}
