<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Tests\Ui\Component;

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
            'filters' => [
                ['name' => 'firm', 'label' => 'Firm', 'options' => self::FIRMS, 'value' => '', 'pinned' => true],
                ['name' => 'month', 'label' => 'Month', 'options' => self::MONTHS, 'value' => '', 'pinned' => true],
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
            ['name' => 'firm', 'label' => 'Firm', 'options' => self::FIRMS, 'value' => 'f1', 'pinned' => true],
            ['name' => 'kind', 'label' => 'Kind', 'options' => self::KINDS, 'value' => 'receipt'],
        ], 'search' => ['name' => 'q', 'value' => 'acme', 'label' => 'Search']]);

        $chip = $crawler->filter('[data-filter-chips] [data-filter-chip=firm][data-set]');
        self::assertStringContainsString('bg-green-tint', (string) $chip->attr('class'));
        self::assertStringContainsString('Firm: Example s.r.o.', $chip->filter('button')->text());
        self::assertSame('/invoices?tab=unpaid&q=acme&kind=receipt', $chip->filter('a[data-filter-clear]')->attr('href'));
        self::assertCount(1, $crawler->filter('[data-filter-chips] [data-filter-chip=kind][data-set]'), 'A filter set from the sheet shows as a chip too.');
        self::assertSame('/invoices?tab=unpaid', $crawler->filter('[data-filter-chips] a[data-filter-clear-all]')->attr('href'));
        self::assertSame('f1', $crawler->filter('form[data-filter-search] input[type=hidden][name=firm]')->attr('value'), 'The search keeps the filters.');
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
        self::assertStringContainsString('sm:hidden', (string) $form->filter('select[name=firm]')->closest('div.min-w-0')?->attr('class'), 'A pinned filter has its field in the sheet for the phone only.');
        self::assertSame('list-filters-sheet-form', $sheet->filter('[data-filter-apply]')->attr('form'));
    }

    public function testAPhoneHasOneFiltersButtonWithTheCount(): void
    {
        $crawler = $this->render(['filters' => [
            ['name' => 'firm', 'label' => 'Firm', 'options' => self::FIRMS, 'value' => 'f2', 'pinned' => true],
        ]]);

        $open = $crawler->filter('[data-filter-open]');
        self::assertStringContainsString('sm:hidden', (string) $open->attr('class'));
        self::assertStringContainsString('(1)', $open->text());
        self::assertStringContainsString('max-sm:hidden', (string) $crawler->filter('[data-filter-chips]')->attr('class'));
        self::assertStringContainsString('Firm: Sample a.s.', $crawler->filter('[data-filter-chips-phone]')->text());
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

    public function testADefaultOtherThanEmptyMeansNotSet(): void
    {
        $crawler = $this->render(['filters' => [
            ['name' => 'filter', 'label' => 'Show', 'options' => ['all' => 'All', 'mine' => 'Mine'], 'value' => 'all', 'default' => 'all', 'pinned' => true],
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

    public function testAtMostTwoPinnedFilters(): void
    {
        $this->expectException(\Throwable::class);
        $this->render(['filters' => [
            ['name' => 'a', 'label' => 'A', 'options' => [], 'value' => '', 'pinned' => true],
            ['name' => 'b', 'label' => 'B', 'options' => [], 'value' => '', 'pinned' => true],
            ['name' => 'c', 'label' => 'C', 'options' => [], 'value' => '', 'pinned' => true],
        ]]);
    }
}
