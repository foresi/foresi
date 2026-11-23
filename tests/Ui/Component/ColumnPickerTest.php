<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\Ui\Component;

use Foresi\Ui\Component\ColumnPicker;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\DomCrawler\Crawler;
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
        self::assertStringNotContainsString('data-column-picker-modified', $this->render());
        $html = $this->render(['modified' => true]);
        self::assertStringContainsString('aria-label="Zobrazení, změněno"', $html);
        self::assertStringContainsString('data-column-picker-modified', $html);
    }

    public function testTheButtonIsASmallIconWithItsLabelAsNameAndTooltip(): void
    {
        $html = $this->render();
        self::assertMatchesRegularExpression('/<button[^>]*aria-label="Zobrazení" title="Zobrazení"[^>]*>/', $html);
        self::assertStringContainsString('data-column-picker-open', $html);
        self::assertStringNotContainsString('ml-auto', $html);
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

    public function testTheShownColumnsAreAListAfterAHiddenOne(): void
    {
        $picker = $this->mount(['columns' => [
            ['key' => 'note', 'label' => 'Note'],
            ['key' => 'name', 'label' => 'Name', 'visible' => true],
        ]]);

        self::assertSame([0], array_keys($picker->shown()));
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

    public function testTheQuickFiltersComeFirstPinnedInOrderThenTheOthersToAdd(): void
    {
        $crawler = new Crawler($this->render(['quick' => [
            ['key' => 'due', 'label' => 'Due', 'pinned' => true],
            ['key' => 'customer', 'label' => 'Customer', 'pinned' => true],
            ['key' => 'paid', 'label' => 'Paid', 'pinned' => false],
        ]]));

        $quick = $crawler->filter('[data-column-picker-quick]');
        self::assertSame('Rychlé filtry', trim($quick->filter('legend')->text()));
        self::assertSame(['due', 'customer'], $quick->filter('[data-column-picker-target=pinRow]')->each(static fn (Crawler $row): string => (string) $row->attr('data-key')));
        self::assertSame(['due', 'customer'], $quick->filter('[data-column-picker-target=pinRow] input[name="pins[]"]:checked')->each(static fn (Crawler $box): string => (string) $box->attr('value')));
        self::assertCount(2, $quick->filter('[data-column-picker-target=pinRow] [data-column-picker-up]'), 'A quick filter moves as a column does.');
        self::assertSame('paid', $quick->filter('[data-column-picker-target=pinOption] input[name="pins[]"]')->attr('value'));
        self::assertSame('', $crawler->filter('input[type=hidden][name="pins[]"]')->attr('value'), 'With no box checked the form still says none.');
        self::assertLessThan(
            (int) strpos($crawler->html(), 'data-column-picker-density'),
            (int) strpos($crawler->html(), 'data-column-picker-quick'),
            'The quick filters come before the columns and the density.',
        );
    }

    public function testAListWithoutColumnsShowsItsQuickFiltersAlone(): void
    {
        $crawler = new Crawler($this->render(['columns' => [], 'density' => null, 'quick' => [['key' => 'person', 'label' => 'Person', 'pinned' => true]]]));

        self::assertCount(1, $crawler->filter('[data-column-picker-quick]'));
        self::assertCount(0, $crawler->filter('input[name="cols[]"], input[name=cols], [data-column-picker-density]'));
        self::assertCount(1, $crawler->filter('[data-column-picker-reset]'));
    }

    public function testNoQuickFiltersNoSection(): void
    {
        $crawler = new Crawler($this->render());

        self::assertCount(0, $crawler->filter('[data-column-picker-quick], input[name="pins[]"], input[name=pins]'));
    }

    public function testThePinsOfTheAddressAreNotKeptAsHiddenFields(): void
    {
        $crawler = new Crawler($this->render(['keep' => ['view' => 'paid', 'pins' => 'due'], 'quick' => [['key' => 'due', 'label' => 'Due', 'pinned' => true]]]));

        self::assertCount(0, $crawler->filter('input[type=hidden][name=pins][value=due]'));
    }

    /**
     * @param array<string, mixed> $props
     */
    private function render(array $props = []): string
    {
        return (string) $this->renderTwigComponent('Ui:ColumnPicker', $props + self::PROPS);
    }
}
