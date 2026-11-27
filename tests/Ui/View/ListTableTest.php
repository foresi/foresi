<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\Ui\View;

use Doctrine\ORM\Tools\SchemaTool;
use Foresi\Shared\Application\ActorRole;
use Foresi\Shared\Application\View\Condition;
use Foresi\Shared\Application\View\DefaultView;
use Foresi\Shared\Application\View\Field;
use Foresi\Shared\Application\View\FieldType;
use Foresi\Shared\Application\View\ListDefinition;
use Foresi\Shared\Application\View\ListSlot;
use Foresi\Shared\Application\View\ListTablePage;
use Foresi\Shared\Application\View\ViewFilter;
use Foresi\Shared\Application\View\WorkingFilter;
use Foresi\Shared\Domain\View\Placement;
use Foresi\Ui\Contract\WorkingState;
use Foresi\Ui\View\ListTable;
use Foresi\Work\Domain\LabelColour;
use Foresi\Work\Infrastructure\Column\LabelColumns;
use Foresi\Work\Infrastructure\Column\LabelRow;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * A short table a page holds in memory as a list with views (docs/design/list-pages.md,
 * rules 8 and 9): its tabs, search, filter and Settings sheet, all in the table's own slot of
 * the address, and the builder's Apply read into its working filter.
 */
final class ListTableTest extends KernelTestCase
{
    private const string ROUTE = 'app_labels';

    private ListDefinition $list;

    protected function setUp(): void
    {
        self::bootKernel();
        $em = self::getContainer()->get('doctrine.orm.entity_manager');
        $metadata = $em->getMetadataFactory()->getAllMetadata();
        $tool = new SchemaTool($em);
        $tool->dropSchema($metadata);
        $tool->createSchema($metadata);
        $actor = self::createStub(ActorRole::class);
        $actor->method('userId')->willReturn(null);
        self::getContainer()->set(ActorRole::class, $actor);
        $this->list = new ListDefinition('test.list_table', [
            new Field('name', FieldType::Text, 'work.labels.field.name'),
            new Field('trashed', FieldType::Boolean, 'work.labels.field.trashed'),
        ], [
            new DefaultView(ListDefinition::ALL, 'work.labels.tab.all', showCount: false),
            new DefaultView('trash', 'work.labels.tab.trash', ViewFilter::all(new Condition('trashed', 'is', true)), position: 1),
            new DefaultView('later', 'work.labels.tab.trash', showCount: false, placement: Placement::Overflow, position: 2),
        ]);
    }

    public function testTheBuildersApplyIsTheWorkingFilterInTheAddressKeepingTheRest(): void
    {
        $rows = ['conditions' => '1', 'c' => [['field' => 'name', 'op' => 'contains', 'value' => 'Al']], 'f' => 'stale'];
        $filter = WorkingFilter::encode(ViewFilter::all(new Condition('name', 'contains', 'Al')));

        self::assertSame(['cols' => 'name', 'view' => 'trash', 'f' => $filter], $this->table()->built(ListSlot::first($rows + ['view' => 'trash', 'cols' => 'name', 'q' => '']), $this->list));
        self::assertSame(['f' => $filter], $this->table()->built(ListSlot::first($rows + ['view' => 'all']), $this->list), 'All is the bare address.');
        self::assertSame(['q' => 'x'], $this->table()->built(ListSlot::first(['conditions' => '1', 'q' => 'x']), $this->list), 'No rows are no filter.');
        self::assertSame(['other' => ['q' => 'y'], 'mine' => ['f' => $filter]], $this->table()->built(ListSlot::named('mine', ['other' => ['q' => 'y'], 'mine' => $rows]), $this->list));
        self::assertNull($this->table()->built(ListSlot::first(['q' => 'x']), $this->list));
    }

    public function testTheSearchIsTrimmedAndCutAndMatchesAnyCase(): void
    {
        $page = $this->page(['q' => '  čtv ']);
        self::assertSame(['name' => 'q', 'value' => 'čtv', 'label' => 'Search'], $page->table['search']);
        self::assertSame(['Čtvrtek'], $this->names($page));
        self::assertTrue($page->table['filtered']);
        self::assertStringContainsString('q=%C4%8Dtv', json_encode($page->table['viewBar'], \JSON_UNESCAPED_SLASHES | \JSON_THROW_ON_ERROR), 'Reset of the view bar keeps the search.');

        self::assertSame(['Alpha'], $this->names($this->page(['q' => 'Al'])), 'The search starts at its first letter.');
        self::assertSame(['name' => 'q', 'value' => str_repeat('č', 100), 'label' => 'Search'], $this->page(['q' => str_repeat('č', 120)])->table['search']);

        $none = $this->page([]);
        self::assertSame(['Alpha', 'Lima', 'Čtvrtek'], $this->names($none), 'The trashed rows hide outside the trash.');
        self::assertFalse($none->table['filtered']);
        self::assertStringNotContainsString('q=', json_encode($none->table['viewBar'], \JSON_UNESCAPED_SLASHES | \JSON_THROW_ON_ERROR));
        self::assertSame([], $none->table['keep']);
    }

    public function testTheTabsOpenEachViewWithItsOwnFilterAndColumnsKeepingTheSearch(): void
    {
        $filter = WorkingFilter::encode(ViewFilter::all(new Condition('trashed', 'is', true), new Condition('name', 'contains', 'Old')));
        $page = $this->page(['view' => 'trash', 'q' => 'Old', 'f' => $filter, 'cols' => 'name']);

        self::assertSame(['Old one'], $this->names($page));
        self::assertSame([
            ['label' => 'Vše', 'href' => '/settings/labels?q=Old', 'active' => false, 'modified' => false, 'private' => false, 'count' => null],
            ['label' => 'Koš', 'href' => '/settings/labels?view=trash&q=Old', 'active' => true, 'modified' => true, 'private' => false, 'count' => 1],
        ], $page->table['tabs']);
        self::assertSame([['label' => 'Koš', 'href' => '/settings/labels?view=later&q=Old', 'active' => false, 'modified' => false, 'private' => false, 'count' => null]], $page->table['moreViews']);
        self::assertSame(['view' => 'trash', 'f' => $filter, 'cols' => 'name'], $page->table['keep']);
        self::assertSame('/settings/labels?view=trash', $page->table['resetHref']);
        self::assertSame('/settings/labels?view=trash&q=Old&cols=name', ($page->href)(ViewFilter::all(new Condition('trashed', 'is', true))), 'The view\'s own filter is the bare view.');
        self::assertSame('/settings/labels?f='.rawurlencode(WorkingFilter::encode(ViewFilter::all(new Condition('name', 'contains', 'A')))).'&q=Old', ($this->page(['q' => 'Old', 'f' => $filter])->href)(ViewFilter::all(new Condition('name', 'contains', 'A'))));
        self::assertSame('/settings/labels', $this->page(['q' => 'x'])->table['resetHref']);
    }

    public function testAViewTheListDoesNotOfferIsNoTabAndTheFirstOfferedOpensByDefault(): void
    {
        $page = $this->page([], static fn (\Foresi\Shared\Application\View\ListView $view): bool => 'all' !== $view->key);

        $tabs = $page->table['tabs'];
        self::assertIsArray($tabs);
        self::assertSame(['Koš'], array_column($tabs, 'label'));
        self::assertSame([true], array_column($tabs, 'active'));
        self::assertSame([['label' => 'Koš', 'href' => '/settings/labels?view=later', 'active' => false, 'modified' => false, 'private' => false, 'count' => null]], $page->table['moreViews']);
        self::assertSame(['Old one'], $this->names($page));
    }

    /**
     * @param array<string, mixed> $query
     */
    private function page(array $query, ?\Closure $offered = null): ListTablePage
    {
        $now = new \DateTimeImmutable('2026-11-01');
        $records = [
            new LabelRow('l1', 'Alpha', LabelColour::Gray, 1),
            new LabelRow('l2', 'Old one', LabelColour::Gray, 0, 'Someone', $now),
            new LabelRow('l3', 'Lima', LabelColour::Green, 2),
            new LabelRow('l4', 'Čtvrtek', LabelColour::Green, 0),
        ];

        return $this->table()->of(ListSlot::first($query), $this->list, LabelColumns::LIST, self::ROUTE, 'labels', $records, static fn (LabelRow $row, WorkingState $working): array => ['label' => $row->name], static fn (LabelRow $row, string $field): mixed => match ($field) {
            'name' => $row->name,
            'trashed' => $row->isTrashed(),
            default => null,
        }, static fn (LabelRow $row): string => $row->name, 'Search', 'trashed', static fn (ViewFilter $shown): array => ['trash' => [] !== $shown->on('trashed')], $offered);
    }

    /**
     * @return array<mixed>
     */
    private function names(ListTablePage $page): array
    {
        $rows = $page->table['rows'];
        self::assertIsArray($rows);
        self::assertIsList($rows);

        return array_column($rows, 'label');
    }

    private function table(): ListTable
    {
        return self::getContainer()->get(ListTable::class);
    }
}
