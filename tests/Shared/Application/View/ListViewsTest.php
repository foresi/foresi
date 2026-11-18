<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\Shared\Application\View;

use Foresi\Shared\Application\View\Condition;
use Foresi\Shared\Application\View\DefaultView;
use Foresi\Shared\Application\View\Field;
use Foresi\Shared\Application\View\FieldType;
use Foresi\Shared\Application\View\ListDefinition;
use Foresi\Shared\Application\View\ListView;
use Foresi\Shared\Application\View\ListViews;
use Foresi\Shared\Application\View\SavedViewStore;
use Foresi\Shared\Application\View\ViewFilter;
use Foresi\Shared\Domain\View\Placement;
use Foresi\Shared\Domain\View\SavedView;
use Foresi\Shared\Domain\View\Visibility;
use PHPUnit\Framework\TestCase;

final class ListViewsTest extends TestCase
{
    private const string OWNER = '0b9a3c6e-3f7c-4a43-9a51-6f6f1d0a0001';
    private const string SOMEONE = '0b9a3c6e-3f7c-4a43-9a51-6f6f1d0a0002';

    public function testAllComesFirstThenTheSharedViewsThenThePrivateOnesEachByPosition(): void
    {
        $views = $this->views([
            $this->saved('mine', null, Visibility::Private, self::OWNER, 0),
            $this->saved('stored-paid', 'test.items.paid', Visibility::Shared, null, 2),
            $this->saved('team', null, Visibility::Shared, null, 1),
            $this->saved('stored-all', 'test.items.all', Visibility::Shared, null, 9),
        ])->of($this->list(), self::OWNER);

        self::assertSame(['all', 'team', 'paid', 'open', 'mine'], array_map(static fn (ListView $view): string => $view->key, $views));
        self::assertSame([false, false, false, false, true], array_map(static fn (ListView $view): bool => $view->private, $views));
        self::assertSame([true, false, true, true, false], array_map(static fn (ListView $view): bool => $view->translated, $views));
        self::assertSame(['all', null, 'paid', 'open', null], array_map(static fn (ListView $view): ?string => $view->slug, $views));
    }

    public function testAPrivateViewShowsToItsOwnerOnly(): void
    {
        $store = $this->views([$this->saved('mine', null, Visibility::Private, self::OWNER, 0)]);

        self::assertCount(4, $store->of($this->list(), self::OWNER));
        self::assertCount(3, $store->of($this->list(), self::SOMEONE));
        self::assertCount(3, $store->of($this->list(), null));
    }

    public function testAHiddenViewAndOneTheListNoLongerAcceptsAreLeftOut(): void
    {
        $views = $this->views([
            $this->saved('hidden', 'test.items.paid', Visibility::Shared, null, 1, hidden: true),
            $this->saved('gone', null, Visibility::Shared, null, 2, filters: ['conditions' => [['field' => 'colour', 'op' => 'is', 'value' => 'red']]]),
        ])->of($this->list(), self::OWNER);

        self::assertSame(['all', 'open'], array_map(static fn (ListView $view): string => $view->key, $views));
    }

    public function testARenamedShippedViewShowsItsNameAsWritten(): void
    {
        $views = $this->views([$this->saved('renamed', 'test.items.paid', Visibility::Shared, null, 1, updatedBy: self::OWNER)])->of($this->list(), self::OWNER);

        self::assertSame('paid', $views[1]->key);
        self::assertFalse($views[1]->translated);
    }

    public function testTheAddressPicksAViewOrFallsBackToAll(): void
    {
        $views = $this->views([])->of($this->list(), null);

        self::assertSame('paid', ListViews::chosen($views, 'paid')->key);
        self::assertSame('all', ListViews::chosen($views, 'nothing')->key);

        $this->expectException(\LogicException::class);
        ListViews::chosen([], 'all');
    }

    public function testAtMostSixTabsAndTheRestInTheMenu(): void
    {
        $views = [$this->view('all', 'all', Placement::Overflow), $this->view('aside', null, Placement::Overflow)];
        foreach (range(1, 6) as $i) {
            $views[] = $this->view('v'.$i, null, Placement::Primary);
        }

        [$primary, $overflow] = ListViews::placed($views);

        self::assertSame(['all', 'v1', 'v2', 'v3', 'v4', 'v5'], array_map(static fn (ListView $view): string => $view->key, $primary));
        self::assertSame(['aside', 'v6'], array_map(static fn (ListView $view): string => $view->key, $overflow));
    }

    public function testAViewIsSharedUnlessSaidOtherwise(): void
    {
        self::assertFalse($this->view('all', 'all', Placement::Primary)->private);
    }

    private function view(string $key, ?string $slug, Placement $placement): ListView
    {
        return new ListView($key, $key, false, new ViewFilter(), true, $placement, slug: $slug);
    }

    /**
     * @param list<SavedView> $saved
     */
    private function views(array $saved): ListViews
    {
        $store = self::createStub(SavedViewStore::class);
        $store->method('ofList')->willReturn($saved);

        return new ListViews($store);
    }

    /**
     * @param array<string, mixed> $filters
     */
    private function saved(string $id, ?string $systemKey, Visibility $visibility, ?string $ownerId, int $position, bool $hidden = false, ?string $updatedBy = null, array $filters = []): SavedView
    {
        $view = self::createStub(SavedView::class);
        $view->method('id')->willReturn($id);
        $view->method('name')->willReturn($id);
        $view->method('systemKey')->willReturn($systemKey);
        $view->method('visibility')->willReturn($visibility);
        $view->method('ownerId')->willReturn($ownerId);
        $view->method('position')->willReturn($position);
        $view->method('isHidden')->willReturn($hidden);
        $view->method('updatedBy')->willReturn($updatedBy);
        $view->method('filters')->willReturn($filters);
        $view->method('showCount')->willReturn(true);
        $view->method('placement')->willReturn(Placement::Primary);

        return $view;
    }

    private function list(): ListDefinition
    {
        return new ListDefinition('test.items', [new Field('paid', FieldType::Boolean, 'Paid')], [
            new DefaultView('all', 'All'),
            new DefaultView('paid', 'Paid', ViewFilter::all(new Condition('paid', 'is', true)), position: 2),
            new DefaultView('open', 'Open', ViewFilter::all(new Condition('paid', 'is', false)), position: 3),
        ]);
    }
}
