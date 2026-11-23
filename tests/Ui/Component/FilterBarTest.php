<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\Ui\Component;

use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\UX\TwigComponent\Test\InteractsWithTwigComponents;

final class FilterBarTest extends KernelTestCase
{
    use InteractsWithTwigComponents;

    private const array FIRMS = ['' => 'All firms', 'f1' => 'Example s.r.o.', 'f2' => 'Sample a.s.'];
    private const array MONTHS = ['' => 'Any month', '2026-11' => 'November 2026'];
    private const array KINDS = ['' => 'Any kind', 'invoice' => 'Invoice', 'receipt' => 'Receipt'];

    /**
     * @param array<string, mixed> $props
     */
    private function render(array $props = []): Crawler
    {
        $html = (string) $this->renderTwigComponent('Ui:FilterBar', $props + [
            'id' => 'list-filters',
            'action' => '/invoices',
            'keep' => ['tab' => 'unpaid', 'sort' => null],
            'search' => ['name' => 'q', 'value' => '', 'label' => 'Search invoices', 'placeholder' => 'Number or customer'],
            'pinned' => ['firm', 'month'],
            'filters' => [
                ['name' => 'firm', 'label' => 'Firm', 'options' => self::FIRMS, 'value' => ''],
                ['name' => 'month', 'label' => 'Month', 'options' => self::MONTHS, 'value' => ''],
                ['name' => 'kind', 'label' => 'Kind', 'options' => self::KINDS, 'value' => ''],
            ],
        ]);

        return new Crawler($html);
    }

    public function testTheSearchAppliesOnEnterKeepingTheTabWithItsButtonOnlyWithoutScript(): void
    {
        $form = $this->render()->filter('form[data-filter-search]');

        self::assertSame('get', $form->attr('method'));
        self::assertSame('/invoices', $form->attr('action'));
        self::assertSame('unpaid', $form->filter('input[type=hidden][name=tab]')->attr('value'));
        self::assertCount(0, $form->filter('input[name=sort]'));
        self::assertCount(1, $form->filter('input[type=search][name=q]'));
        self::assertCount(1, $form->filter('button[type=submit]'));
        self::assertCount(1, $form->filter('noscript button[type=submit]'), 'The search applies on Enter; its button is inside <noscript>.');
    }

    public function testAnEmptyPinnedChipIsADashedPlusMenuOfLinks(): void
    {
        $chip = $this->render()->filter('[data-filter-chip=firm]');

        self::assertCount(1, $chip);
        $trigger = $chip->filter('button[aria-haspopup=menu]');
        self::assertStringContainsString('border-dashed', (string) $trigger->attr('class'));
        self::assertStringContainsString('Firm', $trigger->text());
        $links = $chip->filter('[role=menu] a[role=menuitemradio]');
        self::assertCount(3, $links);
        self::assertSame('/invoices?tab=unpaid&firm=f1', $links->eq(1)->attr('href'));
        self::assertSame('/invoices?tab=unpaid', $links->eq(0)->attr('href'), 'The "all" option clears the filter.');
        self::assertSame('true', $links->eq(0)->attr('aria-checked'));
    }

    public function testASetChipShowsItsValueOnTheTintWithAClearLinkAndClearAllAppears(): void
    {
        $crawler = $this->render(['filters' => [
            ['name' => 'firm', 'label' => 'Firm', 'options' => self::FIRMS, 'value' => 'f1'],
            ['name' => 'kind', 'label' => 'Kind', 'options' => self::KINDS, 'value' => 'receipt'],
        ], 'search' => ['name' => 'q', 'value' => 'acme', 'label' => 'Search']]);

        $chip = $crawler->filter('[data-filter-chips] [data-filter-chip=firm][data-set]');
        self::assertStringContainsString('bg-green-tint', (string) $chip->attr('class'));
        self::assertStringContainsString('Firm: Example s.r.o.', $chip->filter('button')->text());
        self::assertSame('/invoices?tab=unpaid&q=acme&kind=receipt', $chip->filter('a[data-filter-clear]')->attr('href'));
        self::assertCount(1, $crawler->filter('[data-filter-chips] [data-filter-chip=kind][data-set]'), 'A filter set from the sheet shows as a chip too.');
        self::assertSame('/invoices?tab=unpaid', $crawler->filter('[data-filter-row-actions] a[data-filter-clear-all]')->attr('href'));
        self::assertSame('f1', $crawler->filter('form[data-filter-search] input[type=hidden][name=firm]')->attr('value'), 'The search keeps the filters.');
    }

    public function testClearAllDropsTheConditionsOfTheBuilderAndKeepsTheRest(): void
    {
        $crawler = $this->render([
            'keep' => ['tab' => 'unpaid', 'f' => 'eyJ9'],
            'conditions' => [['label' => 'Accountant export: Exported', 'href' => '/invoices?tab=unpaid', 'removeLabel' => 'Remove filter Accountant export']],
            'builder' => ['fields' => [], 'rows' => [], 'kept' => [], 'rules' => [], 'param' => 'f'],
        ]);

        self::assertSame('/invoices?tab=unpaid', $crawler->filter('[data-filter-row-actions] a[data-filter-clear-all]')->attr('href'));
        self::assertSame('/invoices?tab=unpaid', $crawler->filter('a[data-filter-sheet-clear]')->attr('href'));
    }

    public function testClearAllOnlyWhenSomethingIsSet(): void
    {
        self::assertCount(0, $this->render()->filter('a[data-filter-clear-all]'));
    }

    public function testMoreFiltersOpensTheRestInTheNarrowSheetWithOneApply(): void
    {
        $crawler = $this->render();

        $more = $crawler->filter('[data-filter-more]');
        self::assertSame('filter-bar#open', $more->attr('data-action'));
        self::assertSame('list-filters-sheet', $more->attr('aria-controls'));
        $sheet = $crawler->filter('dialog#list-filters-sheet');
        self::assertStringContainsString('w-[400px]', (string) $sheet->attr('class'));
        self::assertSame('#list-filters-sheet', $crawler->filter('[data-filter-bar]')->attr('data-filter-bar-dialog-outlet'));
        $form = $sheet->filter('form[data-filter-sheet-form]');
        self::assertCount(1, $form->filter('select[name=kind]'));
        self::assertCount(1, $form->filter('select[name=firm]'), 'A pinned filter has its field in the sheet too: the filter icon opens it at once.');
        self::assertStringNotContainsString('sm:hidden', (string) $form->filter('select[name=firm]')->closest('div.min-w-0')?->attr('class'));
        self::assertSame('list-filters-sheet-form', $sheet->filter('[data-filter-apply]')->attr('form'));
        self::assertSame('list-filters-kind-field', $form->filter('select[name=kind]')->attr('id'));
    }

    public function testAPhoneHasOneFiltersButtonWithTheCount(): void
    {
        $crawler = $this->render(['filters' => [
            ['name' => 'firm', 'label' => 'Firm', 'options' => self::FIRMS, 'value' => 'f2'],
        ]]);

        $open = $crawler->filter('[data-filter-open]');
        self::assertStringContainsString('sm:hidden', (string) $open->attr('class'));
        self::assertStringContainsString('(1)', $open->text());
        self::assertStringContainsString('max-sm:hidden', (string) $crawler->filter('[data-filter-chips]')->attr('class'));
        self::assertStringContainsString('Firm: Sample a.s.', $crawler->filter('[data-filter-chips-phone]')->text());
    }

    public function testTheConditionsOfTheBuilderAreChipsAndCountOnThePhone(): void
    {
        $crawler = $this->render([
            'filters' => [['name' => 'firm', 'label' => 'Firm', 'options' => self::FIRMS, 'value' => 'f2']],
            'conditions' => [
                ['label' => 'Due date: before today', 'href' => '/invoices?f=a', 'removeLabel' => 'Remove filter Due date'],
                ['label' => 'Total: ≥ 100', 'href' => '/invoices?f=b', 'removeLabel' => 'Remove filter Total'],
            ],
        ]);

        self::assertStringContainsString('(3)', $crawler->filter('[data-filter-open]')->text());
        self::assertSame(['/invoices?f=a', '/invoices?f=b'], $crawler->filter('[data-filter-chips] [data-filter-condition-remove]')->each(static fn (Crawler $link): string => (string) $link->attr('href')));
    }

    public function testWithoutScriptANoscriptFormHoldsEveryFilter(): void
    {
        $noscript = $this->render()->filter('noscript')->last()->html();

        foreach (['name="firm"', 'name="month"', 'name="kind"', 'type="submit"'] as $expected) {
            self::assertStringContainsString($expected, $noscript);
        }
        self::assertStringContainsString('[@media(scripting:none)]:hidden', (string) $this->render()->filter('[data-filter-chips]')->attr('class'));
    }

    public function testNoBarOnAnEmptyListThatNothingNarrows(): void
    {
        self::assertCount(0, $this->render(['empty' => true])->filter('[data-filter-bar]'));
        self::assertCount(1, $this->render(['empty' => true, 'search' => ['name' => 'q', 'value' => 'x', 'label' => 'Search']])->filter('[data-filter-bar]'));
    }

    public function testACollapsibleBarIsFoldedUntilSomethingNarrowsTheList(): void
    {
        // Folded only with script: without it the bar stays open (Shopify Polaris IndexFilters).
        $folded = $this->render(['collapsible' => true, 'pinned' => []])->filter('[data-filter-bar]');
        self::assertNotNull($folded->attr('data-collapsed'));
        self::assertNull($folded->attr('data-filtered'));
        self::assertStringContainsString('[@media(scripting:enabled)]:data-[collapsed]:hidden', (string) $folded->attr('class'));

        self::assertNull($folded->attr('data-row'));

        $searched = $this->render(['collapsible' => true, 'pinned' => [], 'search' => ['name' => 'q', 'value' => 'x', 'label' => 'Search']])->filter('[data-filter-bar]');
        self::assertNull($searched->attr('data-collapsed'));
        self::assertNotNull($searched->attr('data-filtered'));

        $filtered = $this->render(['collapsible' => true, 'pinned' => [], 'conditions' => [['label' => 'Due: before today', 'href' => '/invoices', 'removeLabel' => 'Remove']]])->filter('[data-filter-bar]');
        self::assertNull($filtered->attr('data-collapsed'));

        self::assertNull($this->render()->filter('[data-filter-bar]')->attr('data-collapsed'));
        self::assertNull($this->render(['collapsible' => true])->filter('[data-filter-bar]')->attr('data-collapsed'), 'Pinned chips keep the row open.');
    }

    public function testTheViewsQuickFiltersOpenTheRowUnderTheTabs(): void
    {
        $bar = $this->render(['collapsible' => true, 'pinned' => [], 'quick' => [$this->quick()]])->filter('[data-filter-bar]');

        self::assertNotNull($bar->attr('data-row'));
        self::assertNull($bar->attr('data-collapsed'));
        self::assertNull($bar->attr('data-filtered'), 'An empty quick filter narrows nothing.');
        self::assertCount(0, $bar->filter('[data-filter-row-actions]'));
    }

    public function testAQuickFilterOfValuesIsAMenuOfLinks(): void
    {
        $crawler = $this->render(['pinned' => [], 'quick' => [$this->quick()]]);

        $chip = $crawler->filter('[data-filter-chips] [data-quick-filter=payment]');
        self::assertCount(1, $chip);
        self::assertNull($chip->attr('data-set'));
        self::assertStringContainsString('Payment', $chip->text());
        $links = $crawler->filter('[data-filter-chips] [role=menu] a');
        self::assertSame(['/invoices?f=paid', '/invoices?f=unpaid'], $links->each(static fn (Crawler $link): string => (string) $link->attr('href')));
        self::assertCount(0, $crawler->filter('[data-filter-chips] [data-action="filter-bar#pick"]'), 'Only a date offers a custom range.');
    }

    public function testASetQuickFilterShowsItsValueWithAClearLink(): void
    {
        $crawler = $this->render(['pinned' => [], 'quick' => [$this->quick(['set' => true, 'text' => 'Unpaid'])]]);

        $chip = $crawler->filter('[data-filter-chips] [data-quick-filter=payment][data-set]');
        self::assertStringContainsString('bg-green-tint', (string) $chip->attr('class'));
        self::assertStringContainsString('Payment: Unpaid', $chip->text());
        self::assertSame('/invoices?f=none', $chip->filter('a[data-filter-clear]')->attr('href'));
    }

    public function testAQuickDateOffersACustomRangeInTheSheet(): void
    {
        $crawler = $this->render(['pinned' => [], 'quick' => [$this->quick(['key' => 'issue_date', 'label' => 'Issued', 'pick' => 'months'])]]);

        $custom = $crawler->filter('[data-filter-chips] [data-quick-filter=issue_date] [data-action="filter-bar#pick"]');
        self::assertSame('issue_date', $custom->attr('data-field'));
    }

    public function testAQuickFilterWithoutValuesOpensTheSheetAtItsField(): void
    {
        $crawler = $this->render(['pinned' => [], 'quick' => [$this->quick(['key' => 'customer', 'label' => 'Customer', 'pick' => 'sheet', 'menu' => false, 'groups' => []])]]);

        $button = $crawler->filter('[data-filter-chips] button[data-quick-filter=customer]');
        self::assertSame('filter-bar#pick', $button->attr('data-action'));
        self::assertSame('customer', $button->attr('data-field'));
        self::assertSame('list-filters-sheet', $button->attr('aria-controls'));
    }

    public function testAQuickReferenceSearchesItsRecordsInAPopover(): void
    {
        $crawler = $this->render(['pinned' => [], 'quick' => [$this->quick([
            'key' => 'client', 'label' => 'Client', 'pick' => 'lookup', 'set' => true, 'text' => 'Example s.r.o.', 'groups' => [],
            'lookup' => ['url' => '/contacts/pick', 'base' => '/invoices', 'param' => 'f', 'conditions' => '[]', 'field' => 'client', 'op' => 'is_any_of', 'single' => false,
                'selected' => [['id' => 'c1', 'label' => 'Example s.r.o.', 'href' => '/invoices?f=none']]],
        ])]]);

        $lookup = $crawler->filter('[data-filter-chips] details[data-controller=quick-lookup]');
        self::assertSame('client', $lookup->attr('data-quick-filter'));
        self::assertNotNull($lookup->attr('data-set'));
        self::assertSame('/contacts/pick', $lookup->attr('data-quick-lookup-url-value'));
        self::assertSame('is_any_of', $lookup->attr('data-quick-lookup-op-value'));
        self::assertSame('false', $lookup->attr('data-quick-lookup-single-value'));
        self::assertSame('["c1"]', $lookup->attr('data-quick-lookup-selected-value'));
        self::assertStringContainsString('Client: Example s.r.o.', $lookup->filter('summary')->text());
        self::assertSame('/invoices?f=none', $lookup->filter('[data-quick-lookup-selected] a')->attr('href'), 'A picked record is unpicked by its link.');
        self::assertCount(1, $lookup->filter('[data-quick-lookup-target=input]'));
        self::assertSame('/invoices?f=none', $crawler->filter('[data-filter-chips] a[data-filter-clear]')->attr('href'));
    }

    public function testAtMostFourChipsCountingTheQuickFilters(): void
    {
        $this->expectExceptionMessageMatches('/pins at most 4 filters, 5 given/');

        $this->render(['quick' => [$this->quick(['key' => 'a']), $this->quick(['key' => 'b']), $this->quick(['key' => 'c'])]]);
    }

    public function testTheSearchWaitsForItsIconUntilItHoldsAText(): void
    {
        self::assertNotNull($this->render(['collapsible' => true])->filter('form[data-filter-search]')->attr('data-folded'));
        self::assertNull($this->render(['collapsible' => true, 'search' => ['name' => 'q', 'value' => 'acme', 'label' => 'Search']])->filter('form[data-filter-search]')->attr('data-folded'));
        self::assertNull($this->render()->filter('form[data-filter-search]')->attr('data-folded'), 'A bar without the tabs\' icons shows its search.');
        self::assertStringContainsString('[@media(scripting:enabled)]:data-[folded]:hidden', (string) $this->render(['collapsible' => true])->filter('form[data-filter-search]')->attr('class'));
    }

    public function testTheRowEndsWithTheViewsSaveActionsWhileItHasUnsavedChanges(): void
    {
        $viewBar = ['modified' => true, 'canSave' => false, 'resetHref' => '/invoices?view=v1'];
        $bar = $this->render(['collapsible' => true, 'pinned' => [], 'viewBar' => $viewBar])->filter('[data-filter-bar]');

        self::assertNull($bar->attr('data-collapsed'), 'Unsaved changes keep the row open.');
        $actions = $bar->filter('[data-filter-row-actions] [data-view-actions]');
        self::assertCount(1, $actions);
        self::assertSame('/invoices?view=v1', $actions->filter('[data-view-reset]')->attr('href'));
        self::assertCount(1, $actions->filter('[data-view-save-as-button]'));
        self::assertCount(0, $actions->filter('[data-view-save]'), 'Save to view only for who may change the view.');
        self::assertCount(0, $bar->filter('[data-filter-row-actions] a[data-filter-clear-all]'), 'Nothing narrows the list, so nothing to clear.');

        self::assertCount(0, $this->render(['viewBar' => ['modified' => false] + $viewBar])->filter('[data-view-actions]'));
    }

    /**
     * A quick filter as FilterSheet gives it, of an enum by default.
     *
     * @param array<string, mixed> $over
     *
     * @return array<string, mixed>
     */
    private function quick(array $over = []): array
    {
        return $over + [
            'key' => 'payment',
            'label' => 'Payment',
            'pick' => 'values',
            'menu' => true,
            'multi' => true,
            'set' => false,
            'text' => '',
            'clearHref' => '/invoices?f=none',
            'groups' => [['label' => null, 'options' => [
                ['label' => 'Paid', 'href' => '/invoices?f=paid', 'checked' => false],
                ['label' => 'Unpaid', 'href' => '/invoices?f=unpaid', 'checked' => false],
            ]]],
            'lookup' => null,
        ];
    }

    public function testADefaultOtherThanEmptyMeansNotSet(): void
    {
        $crawler = $this->render(['pinned' => ['filter'], 'filters' => [
            ['name' => 'filter', 'label' => 'Show', 'options' => ['all' => 'All', 'mine' => 'Mine'], 'value' => 'all', 'default' => 'all'],
        ]]);

        self::assertCount(0, $crawler->filter('[data-set]'));
        self::assertSame('/invoices?tab=unpaid', $crawler->filter('[role=menu] a')->eq(0)->attr('href'));
    }

    public function testADateIsPickedInTheSheetAndShowsAsAChipWithoutAMenu(): void
    {
        $crawler = $this->render(['filters' => [
            ['name' => 'from', 'label' => 'From', 'type' => 'date', 'value' => '2026-11-01', 'text' => '1. 11. 2026'],
        ]]);

        self::assertSame('date', $crawler->filter('[data-filter-sheet-form] input[name=from]')->attr('type'));
        $chip = $crawler->filter('[data-filter-chips] [data-filter-chip=from]');
        self::assertStringContainsString('From: 1. 11. 2026', $chip->text());
        self::assertCount(0, $chip->filter('[role=menu]'));
    }

    /**
     * @return iterable<string, array{array<string, mixed>, string}>
     */
    public static function invalid(): iterable
    {
        $filter = ['name' => 'firm', 'label' => 'Firm', 'options' => self::FIRMS, 'value' => ''];
        yield 'no id' => [['id' => ''], 'needs an id'];
        yield 'no address' => [['action' => ''], 'address of its list'];
        yield 'a search without a name' => [['search' => ['label' => 'Search']], 'search of a filter bar'];
        yield 'a search without a label' => [['search' => ['name' => 'q']], 'search of a filter bar'];
        yield 'a filter without a name' => [['filters' => [['name' => null] + $filter]], 'needs a name, a label'];
        yield 'a filter without a label' => [['filters' => [['label' => null] + $filter]], 'needs a name, a label'];
        yield 'a select without options' => [['filters' => [['options' => null] + $filter]], 'needs a name, a label'];
        yield 'an unknown type' => [['filters' => [['type' => 'range'] + $filter]], 'Unknown filter type "range"'];
        yield 'a pinned date' => [['pinned' => ['firm'], 'filters' => [['type' => 'date'] + $filter]], 'cannot be pinned'];
        yield 'a filter that pins itself' => [['filters' => [['pinned' => true] + $filter]], 'not by itself'];
        yield 'five pinned' => [['pinned' => ['a', 'b', 'c', 'd', 'e'], 'filters' => [
            ['name' => 'a'] + $filter,
            ['name' => 'b'] + $filter,
            ['name' => 'c'] + $filter,
            ['name' => 'd'] + $filter,
            ['name' => 'e'] + $filter,
        ]], 'pins at most 4 filters, 5 given'];
    }

    /**
     * @param array<string, mixed> $props
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('invalid')]
    public function testAnInvalidBarThrows(array $props, string $message): void
    {
        try {
            $this->render($props);
        } catch (\Throwable $error) {
            $messages = [];
            for ($e = $error; $e instanceof \Throwable; $e = $e->getPrevious()) {
                $messages[] = $e->getMessage();
            }
            self::assertStringContainsString($message, implode(' | ', $messages));

            return;
        }
        self::fail('The bar rendered.');
    }

    public function testAnUnsetDateMayStayOutOfThePinnedChips(): void
    {
        $crawler = $this->render(['filters' => [
            ['name' => 'firm', 'label' => 'Firm', 'options' => self::FIRMS, 'value' => ''],
            ['name' => 'from', 'label' => 'From', 'type' => 'date', 'value' => ''],
        ]]);

        self::assertCount(0, $crawler->filter('[data-filter-chips] [data-filter-chip=from]'));
        self::assertCount(1, $crawler->filter('[data-filter-more]'));
    }

    public function testNoMoreFiltersWhenEveryFilterIsPinned(): void
    {
        $crawler = $this->render(['filters' => [
            ['name' => 'firm', 'label' => 'Firm', 'options' => self::FIRMS, 'value' => 'f1'],
        ]]);

        self::assertCount(0, $crawler->filter('[data-filter-more]'));
        self::assertCount(1, $crawler->filter('[data-filter-chips] [data-filter-chip=firm]'), 'A pinned chip shows once, not again as a set filter.');
    }

    public function testNumbersAreValuesLikeAnyOther(): void
    {
        $crawler = $this->render([
            'keep' => ['tab' => 'unpaid', 'sort' => 'number', 'toSend' => true, 'trash' => false, 'page' => 2],
            'pinned' => ['year'],
            'filters' => [
                ['name' => 'year', 'label' => 'Year', 'options' => [0 => 'Any year', 2025 => '2025', 2026 => '2026'], 'value' => 2026, 'default' => 0],
            ],
        ]);

        $chip = $crawler->filter('[data-filter-chips] [data-filter-chip=year][data-set]');
        self::assertStringContainsString('Year: 2026', $chip->filter('button')->text());
        $links = $chip->filter('[role=menu] a');
        self::assertSame('true', $links->eq(2)->attr('aria-checked'));
        self::assertSame('/invoices?tab=unpaid&sort=number&toSend=1&page=2', $links->eq(0)->attr('href'));
        self::assertSame('/invoices?tab=unpaid&sort=number&toSend=1&page=2&year=2025', $links->eq(1)->attr('href'));
    }

    public function testTheSheetKeepsTheSearchAndTheSearchKeepsTheFilters(): void
    {
        $crawler = $this->render([
            'search' => ['name' => 'q', 'value' => 'acme', 'label' => 'Search'],
            'filters' => [['name' => 'kind', 'label' => 'Kind', 'options' => self::KINDS, 'value' => 'invoice']],
        ]);

        self::assertSame('acme', $crawler->filter('[data-filter-sheet-form] input[type=hidden][name=q]')->attr('value'));
        self::assertCount(0, $crawler->filter('[data-filter-sheet-form] input[type=hidden][name=kind]'), 'The sheet sends its own field.');
        self::assertCount(0, $crawler->filter('form[data-filter-search] input[type=hidden][name=q]'));
        self::assertSame('invoice', $crawler->filter('form[data-filter-search] input[type=hidden][name=kind]')->attr('value'));
    }
}
