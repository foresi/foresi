<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\Shared\Application\View;

use Foresi\Shared\Application\ActorRole;
use Foresi\Shared\Application\View\Condition;
use Foresi\Shared\Application\View\CreateViewInput;
use Foresi\Shared\Application\View\DefaultView;
use Foresi\Shared\Application\View\DeleteViewInput;
use Foresi\Shared\Application\View\Field;
use Foresi\Shared\Application\View\FieldType;
use Foresi\Shared\Application\View\ListDefinition;
use Foresi\Shared\Application\View\ListDefinitions;
use Foresi\Shared\Application\View\ListDefinitionSource;
use Foresi\Shared\Application\View\RestoreDefaultViewsInput;
use Foresi\Shared\Application\View\SavedViewRefused;
use Foresi\Shared\Application\View\SavedViews;
use Foresi\Shared\Application\View\SavedViewStore;
use Foresi\Shared\Application\View\UpdateViewInput;
use Foresi\Shared\Application\View\ViewFilter;
use Foresi\Shared\Contract\Operation\Field as ApiField;
use Foresi\Shared\Contract\RefusalKind;
use Foresi\Shared\Domain\View\Placement;
use Foresi\Shared\Domain\View\SavedView;
use Foresi\Shared\Domain\View\Visibility;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;

final class SavedViewsTest extends TestCase
{
    private const string OWNER = '0b9a3c6e-3f7c-4a43-9a51-6f6f1d0a0001';
    private const string MEMBER = '0b9a3c6e-3f7c-4a43-9a51-6f6f1d0a0002';
    private const string OTHER_MEMBER = '0b9a3c6e-3f7c-4a43-9a51-6f6f1d0a0003';

    private InMemoryViews $store;
    private FakeActor $actor;
    private SavedViews $views;

    protected function setUp(): void
    {
        $this->store = new InMemoryViews();
        $this->actor = new FakeActor(self::MEMBER, staff: true, owner: false);
        $lists = new ListDefinitions([new class implements ListDefinitionSource {
            public function lists(): iterable
            {
                yield new ListDefinition('test.items', [new Field('paid', FieldType::Boolean, 'Paid'), new Field('due_date', FieldType::Date, 'Due date', aliases: ['due'])], [
                    new DefaultView('all', 'All'),
                    new DefaultView('paid', 'Paid', ViewFilter::all(new Condition('paid', 'is', true)), position: 1),
                ], ['due_date']);
            }
        }]);
        $this->views = new SavedViews($this->store, $lists, $this->actor, new MockClock('2026-11-18 10:00:00'));
    }

    public function testAStaffMemberMakesASharedViewThatTheOtherStaffSee(): void
    {
        $view = $this->views->create($this->input('Due this week', 'shared', [['field' => 'due_date', 'op' => 'is', 'value' => ['relative' => 'this_week']]]));

        self::assertSame(self::MEMBER, $view->ownerId());
        self::assertSame(Visibility::Shared, $view->visibility());
        self::assertSame(['match' => 'all', 'conditions' => [['field' => 'due_date', 'op' => 'is', 'value' => ['relative' => 'this_week']]]], $view->filters());
        self::assertSame(2, $view->position(), 'A new view comes after the shipped ones.');

        $this->actor->userId = self::OTHER_MEMBER;
        self::assertContains($view, $this->views->visible('test.items'));
        self::assertSame($view, $this->views->get($view->id()));
    }

    public function testAPrivateViewIsItsOwnersAlone(): void
    {
        $view = $this->views->create($this->input('Mine', 'private'));

        $this->actor->userId = self::OWNER;
        $this->actor->owner = true;
        self::assertNotContains($view, $this->views->visible('test.items'));
        $this->assertRefused(RefusalKind::NotFound, fn (): SavedView => $this->views->get($view->id()));
        $this->assertRefused(RefusalKind::NotFound, fn (): string => $this->views->delete(new DeleteViewInput($view->id())));
    }

    public function testASharedViewIsChangedByItsCreatorAndTheOwnersOnly(): void
    {
        $view = $this->views->create($this->input('Team', 'shared'));

        $this->actor->userId = self::OTHER_MEMBER;
        $this->assertRefused(RefusalKind::Forbidden, fn (): SavedView => $this->views->update(new UpdateViewInput($view->id(), name: 'Mine now')));
        $this->assertRefused(RefusalKind::Forbidden, fn (): string => $this->views->delete(new DeleteViewInput($view->id())));

        $this->actor->userId = self::OWNER;
        $this->actor->owner = true;
        $this->views->update(new UpdateViewInput($view->id(), name: 'Renamed by an owner', visibility: 'private'));
        self::assertSame('Renamed by an owner', $view->name());
        self::assertSame(Visibility::Private, $view->visibility());
        self::assertSame(self::MEMBER, $view->ownerId(), 'Made private by an owner, it stays its creator\'s.');

        $this->actor->userId = self::MEMBER;
        $this->actor->owner = false;
        self::assertSame($view->id(), $this->views->delete(new DeleteViewInput($view->id())));
        self::assertNull($this->store->get($view->id()));
    }

    public function testOnlyItsCreatorSharesAPrivateView(): void
    {
        $view = $this->views->create($this->input('Mine', 'private'));

        $this->views->update(new UpdateViewInput($view->id(), visibility: 'shared'));

        self::assertSame(Visibility::Shared, $view->visibility());
    }

    public function testAShippedViewIsChangedAndHiddenByOwnersAndNeverDeleted(): void
    {
        $paid = $this->views->get('test.items.paid');
        self::assertNull($paid->ownerId());

        $this->assertRefused(RefusalKind::Forbidden, fn (): SavedView => $this->views->update(new UpdateViewInput($paid->id(), name: 'Settled')));

        $this->actor->userId = self::OWNER;
        $this->actor->owner = true;
        $this->views->update(new UpdateViewInput($paid->id(), name: 'Settled', hidden: true));
        self::assertSame('Settled', $paid->name());
        self::assertTrue($paid->isHidden());
        self::assertFalse($paid->isPristine());

        $this->assertRefused(RefusalKind::Conflict, fn (): string => $this->views->delete(new DeleteViewInput($paid->id())));
        $this->assertRefused(RefusalKind::Invalid, fn (): SavedView => $this->views->update(new UpdateViewInput($paid->id(), visibility: 'private')));

        $this->views->restoreDefaults(new RestoreDefaultViewsInput('test.items'));
        self::assertSame('Paid', $paid->name());
        self::assertFalse($paid->isHidden());
        self::assertTrue($paid->isPristine());
    }

    public function testOnlyOwnersRestoreTheDefaults(): void
    {
        $this->assertRefused(RefusalKind::Forbidden, fn (): array => $this->views->restoreDefaults(new RestoreDefaultViewsInput('test.items')));
    }

    public function testGuestsHaveNoViews(): void
    {
        $this->actor->staff = false;

        $this->assertRefused(RefusalKind::Forbidden, fn (): array => $this->views->visible('test.items'));
        $this->assertRefused(RefusalKind::Forbidden, fn (): SavedView => $this->views->create($this->input('Guest', 'private')));
    }

    /**
     * @return iterable<string, array{\Closure(): CreateViewInput, string}>
     */
    public static function invalidInputs(): iterable
    {
        yield 'unknown field' => [static fn (): CreateViewInput => new CreateViewInput('test.items', 'Broken', conditions: [['field' => 'colour', 'op' => 'is', 'value' => 'red']]), 'conditions[0].field'];
        yield 'operator of another type' => [static fn (): CreateViewInput => new CreateViewInput('test.items', 'Broken', conditions: [['field' => 'paid', 'op' => 'before', 'value' => true]]), 'conditions[0].op'];
        yield 'bad relative rule' => [static fn (): CreateViewInput => new CreateViewInput('test.items', 'Broken', conditions: [['field' => 'due_date', 'op' => 'is', 'value' => ['relative' => 'next_decade']]]), 'conditions[0].value'];
        yield 'bad sort' => [static fn (): CreateViewInput => new CreateViewInput('test.items', 'Broken', sort: [['field' => 'due_date', 'dir' => 'sideways']]), 'sort[0].dir'];
        yield 'bad column' => [static fn (): CreateViewInput => new CreateViewInput('test.items', 'Broken', columns: [['key' => 'number', 'width' => -4]]), 'columns[0].width'];
        yield 'bad grouping' => [static fn (): CreateViewInput => new CreateViewInput('test.items', 'Broken', groupBy: 'by what?'), 'group_by'];
        yield 'empty name' => [static fn (): CreateViewInput => new CreateViewInput('test.items', '  '), 'name'];
        yield 'unknown list' => [static fn (): CreateViewInput => new CreateViewInput('test.nothing', 'Broken'), 'listKey'];
    }

    /**
     * @param \Closure(): CreateViewInput $input
     */
    #[DataProvider('invalidInputs')]
    public function testAnInvalidViewIsRefusedWithThePathOfWhatIsWrong(\Closure $input, string $path): void
    {
        try {
            $this->views->create($input());
            self::fail('The view was made.');
        } catch (SavedViewRefused $refused) {
            self::assertSame(RefusalKind::Invalid, $refused->kind());
            self::assertTrue(str_starts_with((string) $refused->field(), $path), (string) $refused->field());
        }
    }

    public function testChangingTheGroupingKeepsTheColumns(): void
    {
        $view = $this->views->create(new CreateViewInput('test.items', 'Cols', columns: ['number', ['key' => 'customer', 'width' => 240]]));

        $this->views->update(new UpdateViewInput($view->id(), groupBy: 'customer'));

        self::assertSame(['columns' => [['key' => 'number'], ['key' => 'customer', 'width' => 240]], 'group_by' => 'customer'], $view->display());
    }

    public function testTheViewsOfAListAreInOrderWithTheOthersPrivateOnesLeftOut(): void
    {
        $this->actor->userId = self::OTHER_MEMBER;
        $this->views->create($this->input('Theirs', 'private'));
        $this->actor->userId = self::MEMBER;
        $mine = $this->views->create($this->input('Mine', 'shared'));

        $visible = $this->views->visible('test.items');

        self::assertSame([0, 1, 2], array_keys($visible));
        self::assertSame(['All', 'Paid', 'Mine'], array_map(static fn (SavedView $view): string => $view->name(), $visible));
        self::assertSame($mine, $visible[2]);
    }

    public function testReadingAListStoresTheShippedViewsOnceWithOneFlush(): void
    {
        self::assertCount(2, $this->views->visible('test.items'));
        self::assertSame(1, $this->store->flushes);

        $this->views->visible('test.items');
        self::assertSame(1, $this->store->flushes, 'Nothing is stored twice.');
    }

    public function testAShippedViewIsReadByItsSystemKeyAndStoredThen(): void
    {
        $paid = $this->views->get('test.items.paid');

        self::assertSame('test.items.paid', $paid->systemKey());
        self::assertSame($paid, $this->store->bySystemKey('test.items.paid'));
        self::assertSame(1, $this->store->flushes);
        self::assertSame($paid, $this->views->get($paid->id()));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function unknownSystemKeys(): iterable
    {
        yield 'list key without the dot' => ['test.itemsXall'];
        yield 'view the list does not ship' => ['test.items.nothing'];
        yield 'another list' => ['test.other.all'];
        yield 'a view name only' => ['zzzzzzzzzzzall'];
    }

    #[DataProvider('unknownSystemKeys')]
    public function testAnUnknownSystemKeyIsNotFoundAndStoresNothing(string $key): void
    {
        $this->assertRefused(RefusalKind::NotFound, fn (): SavedView => $this->views->get($key));

        self::assertSame([], $this->store->ofList('test.items'));
        self::assertSame(0, $this->store->flushes);
    }

    public function testEachNewViewComesAfterTheLastOne(): void
    {
        $first = $this->views->create(new CreateViewInput('test.items', 'First'));
        $second = $this->views->create(new CreateViewInput('test.items', 'Second'));

        self::assertSame([2, 3], [$first->position(), $second->position()]);
    }

    public function testAViewIsMadeWithWhatWasAskedAndFlushedOnce(): void
    {
        $view = $this->views->create(new CreateViewInput('test.items', 'Full', 'shared', [], [['field' => 'due_date']], ['number'], true, 'overflow'));

        self::assertSame(1, $this->store->flushes - 1, 'The shipped views are stored with the first flush, the view with one more.');
        self::assertSame([['field' => 'due_date', 'dir' => 'asc']], $view->sort());
        self::assertSame(['columns' => [['key' => 'number']]], $view->display());
        self::assertTrue($view->showCount());
        self::assertSame(Placement::Overflow, $view->placement());
        self::assertSame($view, $this->store->get($view->id()));
        self::assertEquals(new \DateTimeImmutable('2026-11-18 10:00:00'), $view->createdAt());
    }

    public function testAViewIsNotShowingItsCountUnlessAsked(): void
    {
        self::assertFalse($this->views->create(new CreateViewInput('test.items', 'Quiet'))->showCount());
    }

    public function testViewNamesAreTrimmedAndLimitedToSixtyCharactersNotBytes(): void
    {
        self::assertSame('Padded', $this->views->create(new CreateViewInput('test.items', '  Padded  '))->name());
        self::assertSame(str_repeat('a', 60), $this->views->create(new CreateViewInput('test.items', str_repeat('a', 60)))->name());
        self::assertSame(str_repeat('č', 60), $this->views->create(new CreateViewInput('test.items', str_repeat('č', 60)))->name());

        $this->assertRefusedBy('name_invalid', 'name', fn (): SavedView => $this->views->create(new CreateViewInput('test.items', str_repeat('a', 61))));
        $this->assertRefusedBy('name_invalid', 'name', fn (): SavedView => $this->views->create(new CreateViewInput('test.items', str_repeat('č', 61))));
        $this->assertRefusedBy('name_invalid', 'name', fn (): SavedView => $this->views->update(new UpdateViewInput($this->views->create($this->input('Mine', 'private'))->id(), name: ' ')));
    }

    public function testAnUnknownVisibilityOrPlacementIsRefused(): void
    {
        $this->assertRefusedBy('visibility_unknown', 'visibility', fn (): SavedView => $this->views->create(new CreateViewInput('test.items', 'A', 'secret')));
        $this->assertRefusedBy('placement_unknown', 'placement', fn (): SavedView => $this->views->create(new CreateViewInput('test.items', 'A', placement: 'sideways')));

        $view = $this->views->create($this->input('Mine', 'private'));
        $this->assertRefusedBy('visibility_unknown', 'visibility', fn (): SavedView => $this->views->update(new UpdateViewInput($view->id(), visibility: 'secret')));
        $this->assertRefusedBy('placement_unknown', 'placement', fn (): SavedView => $this->views->update(new UpdateViewInput($view->id(), placement: 'sideways')));
    }

    public function testAnOwnViewIsNeverHidden(): void
    {
        $view = $this->views->create($this->input('Mine', 'private'));
        $flushes = $this->store->flushes;

        $this->assertRefusedBy('hide_own_view', 'hidden', fn (): SavedView => $this->views->update(new UpdateViewInput($view->id(), hidden: true)));
        self::assertFalse($view->isHidden());

        $this->views->update(new UpdateViewInput($view->id(), hidden: false));
        self::assertSame($flushes + 1, $this->store->flushes, 'Saying it is shown is no change.');
    }

    public function testTheCreatorMakesAViewPrivateAndSharesItAgain(): void
    {
        $view = $this->views->create($this->input('Team', 'shared'));

        $this->views->update(new UpdateViewInput($view->id(), visibility: 'private'));
        self::assertSame(Visibility::Private, $view->visibility());
        self::assertSame(self::MEMBER, $view->updatedBy());

        $this->views->update(new UpdateViewInput($view->id(), visibility: 'shared'));
        self::assertSame(Visibility::Shared, $view->visibility());

        $this->actor->userId = self::OWNER;
        $this->actor->owner = true;
        $this->views->update(new UpdateViewInput($view->id(), visibility: 'private'));
        self::assertSame(self::OWNER, $view->updatedBy());
        $this->assertRefused(RefusalKind::NotFound, fn (): SavedView => $this->views->update(new UpdateViewInput($view->id(), visibility: 'shared')));
    }

    public function testTheGroupingIsKeptWithTheDisplay(): void
    {
        $view = $this->views->create(new CreateViewInput('test.items', 'Grouped', groupBy: 'customer'));
        self::assertSame(['group_by' => 'customer'], $view->display());

        $this->views->update(new UpdateViewInput($view->id(), columns: ['number']));
        self::assertSame(['columns' => [['key' => 'number']], 'group_by' => 'customer'], $view->display(), 'changing the columns keeps it');
        $this->views->update(new UpdateViewInput($view->id(), groupBy: 'status'));
        self::assertSame(['columns' => [['key' => 'number']], 'group_by' => 'status'], $view->display());
        $this->views->update(new UpdateViewInput($view->id(), groupBy: null));
        self::assertSame(['columns' => [['key' => 'number']]], $view->display());
    }

    public function testAViewKeepsItsQuickFiltersWithItsDisplayUnderTheirKeys(): void
    {
        $view = $this->views->create(new CreateViewInput('test.items', 'Pinned', columns: ['number'], pinned: ['due', 'paid']));
        self::assertSame(['columns' => [['key' => 'number']], 'pinned' => ['due_date', 'paid']], $view->display(), 'An alias is stored as the key it names.');

        $this->views->update(new UpdateViewInput($view->id(), groupBy: 'customer'));
        self::assertSame(['columns' => [['key' => 'number']], 'group_by' => 'customer', 'pinned' => ['due_date', 'paid']], $view->display(), 'Changing the grouping keeps them.');

        $this->views->update(new UpdateViewInput($view->id(), pinned: []));
        self::assertSame(['columns' => [['key' => 'number']], 'group_by' => 'customer', 'pinned' => []], $view->display(), 'None pins none.');

        $this->views->update(new UpdateViewInput($view->id(), pinned: null));
        self::assertSame(['columns' => [['key' => 'number']], 'group_by' => 'customer'], $view->display(), 'Null goes back to the list\'s own.');
    }

    public function testAQuickFilterOnAFieldTheListLacksIsRefused(): void
    {
        $this->assertRefusedBy('pinned_unknown', 'pinned[1]', fn (): SavedView => $this->views->create(new CreateViewInput('test.items', 'Bad', pinned: ['paid', 'customer'])));
        $this->assertRefusedBy('filter_invalid', 'pinned', fn (): SavedView => $this->views->create(new CreateViewInput('test.items', 'Many', pinned: ['paid', 'paid', 'paid', 'paid', 'paid'])));
    }

    public function testTheStoredQuickFiltersAreReadInTheirOrderWhenTheRestOfTheDisplayChanges(): void
    {
        $view = $this->views->create(new CreateViewInput('test.items', 'Stored', columns: ['number']));
        $view->redisplay(['pinned' => [3 => 'paid', 5 => 'Not a key']], self::MEMBER, new \DateTimeImmutable('2026-11-18 10:00:00'));

        $this->assertRefusedBy('filter_invalid', 'pinned[1]', fn (): SavedView => $this->views->update(new UpdateViewInput($view->id(), groupBy: 'customer')));
    }

    public function testARefusedQuickFilterIsNamedInTheMessage(): void
    {
        try {
            $this->views->create(new CreateViewInput('test.items', 'Bad', pinned: ['paid', 'customer']));
            self::fail('It was allowed.');
        } catch (SavedViewRefused $refused) {
            self::assertSame('The list "test.items" has no field "customer" to pin; find its fields with foresi_list_lists.', $refused->getMessage());
        }
        try {
            $this->views->create(new CreateViewInput('test.items', 'Bad', pinned: [7]));
            self::fail('It was allowed.');
        } catch (SavedViewRefused $refused) {
            self::assertSame('The list "test.items" has no field of that kind to pin; find its fields with foresi_list_lists.', $refused->getMessage());
        }
    }

    public function testAShippedViewKeepsNoQuickFiltersAndShowsItsListsOwn(): void
    {
        $paid = $this->views->get('test.items.paid');

        self::assertNull($paid->display(), 'A shipped view stores no display: it shows its list\'s default quick filters.');
        self::assertSame(['due_date'], $this->views->list('test.items')->pinnedOf(null));
    }

    public function testUpdatingOnlyTheColumnsKeepsTheGroupingAndTheOtherWayRound(): void
    {
        $view = $this->views->create(new CreateViewInput('test.items', 'Cols', columns: ['number'], groupBy: 'customer'));

        $this->views->update(new UpdateViewInput($view->id(), columns: ['customer']));
        self::assertSame(['columns' => [['key' => 'customer']], 'group_by' => 'customer'], $view->display());

        $this->views->update(new UpdateViewInput($view->id(), groupBy: null));
        self::assertSame(['columns' => [['key' => 'customer']]], $view->display());

        $this->views->update(new UpdateViewInput($view->id(), columns: null, groupBy: 'status'));
        self::assertSame(['group_by' => 'status'], $view->display());
    }

    public function testChangingSomethingElseLeavesTheStoredDisplayAsItIs(): void
    {
        $view = SavedView::made('test.items', self::MEMBER, 'Old', Visibility::Private, ['match' => 'all', 'conditions' => []], [], ['columns' => [['key' => 'Gone column!']]], false, Placement::Primary, 5, new \DateTimeImmutable('2026-11-17'));
        $this->store->add($view);

        $this->views->update(new UpdateViewInput($view->id(), name: 'New'));

        self::assertSame(['columns' => [['key' => 'Gone column!']]], $view->display());
        self::assertSame('New', $view->name());
    }

    public function testTheStoredColumnsAreCheckedAsAListWhenTheGroupingChanges(): void
    {
        $view = SavedView::made('test.items', self::MEMBER, 'Old', Visibility::Private, ['match' => 'all', 'conditions' => []], [], ['columns' => [3 => ['key' => 'number'], 9 => ['key' => 'Gone column!']]], false, Placement::Primary, 5, new \DateTimeImmutable('2026-11-17'));
        $this->store->add($view);

        $this->assertRefusedBy('filter_invalid', 'columns[1].key', fn (): SavedView => $this->views->update(new UpdateViewInput($view->id(), groupBy: 'customer')));
    }

    public function testAnUpdateChangesWhatItIsGivenAndFlushesOnce(): void
    {
        $view = $this->views->create($this->input('Mine', 'private'));
        $flushes = $this->store->flushes;

        $this->views->update(new UpdateViewInput($view->id(), conditions: [['field' => 'paid', 'op' => 'is', 'value' => true]], sort: [['field' => 'due_date', 'dir' => 'desc']], showCount: true, placement: 'overflow', position: 9));

        self::assertSame($flushes + 1, $this->store->flushes);
        self::assertSame([['field' => 'paid', 'op' => 'is', 'value' => true]], $view->filters()['conditions']);
        self::assertSame([['field' => 'due_date', 'dir' => 'desc']], $view->sort());
        self::assertTrue($view->showCount());
        self::assertSame(Placement::Overflow, $view->placement());
        self::assertSame(9, $view->position());
    }

    public function testDeletingFlushesOnce(): void
    {
        $view = $this->views->create($this->input('Mine', 'private'));
        $flushes = $this->store->flushes;

        $this->views->delete(new DeleteViewInput($view->id()));

        self::assertSame($flushes + 1, $this->store->flushes);
    }

    public function testRestoringStoresTheShippedViewsAndReturnsAllOfThem(): void
    {
        $this->actor->userId = self::OWNER;
        $this->actor->owner = true;

        $restored = $this->views->restoreDefaults(new RestoreDefaultViewsInput('test.items'));

        self::assertSame(['All', 'Paid'], array_map(static fn (SavedView $view): string => $view->name(), $restored));
        self::assertSame(2, $this->store->flushes, 'The shipped views are stored, then restored.');
    }

    public function testRestoringLeavesTheViewsPeopleMadeAlone(): void
    {
        $mine = $this->views->create($this->input('Mine', 'shared'));
        $this->actor->userId = self::OWNER;
        $this->actor->owner = true;
        $this->views->update(new UpdateViewInput($mine->id(), name: 'Renamed'));

        $restored = $this->views->restoreDefaults(new RestoreDefaultViewsInput('test.items'));

        self::assertNotContains($mine, $restored);
        self::assertSame('Renamed', $mine->name());
    }

    public function testRestoringNeedsAStaffMemberWhoIsKnown(): void
    {
        $this->actor->owner = true;
        $this->actor->userId = null;

        $this->assertRefused(RefusalKind::Forbidden, fn (): array => $this->views->restoreDefaults(new RestoreDefaultViewsInput('test.items')));
        self::assertSame(0, $this->store->flushes);
    }

    public function testWhoMayEditAView(): void
    {
        $mine = $this->views->create($this->input('Mine', 'private'));
        $paid = $this->views->get('test.items.paid');

        self::assertTrue($this->views->mayEdit($mine));
        self::assertFalse($this->views->mayEdit($paid));

        $this->actor->owner = true;
        self::assertTrue($this->views->mayEdit($paid));

        $this->actor->owner = false;
        $this->actor->userId = self::OTHER_MEMBER;
        self::assertFalse($this->views->mayEdit($mine));

        $this->actor->userId = null;
        self::assertFalse($this->views->mayEdit($mine));
    }

    public function testTheFilterOfAViewIsCheckedByItsList(): void
    {
        $view = $this->views->create($this->input('Paid', 'shared', [['field' => 'paid', 'op' => 'is', 'value' => true]]));

        self::assertSame(['match' => 'all', 'conditions' => [['field' => 'paid', 'op' => 'is', 'value' => true]]], $this->views->filterOf($view)->toArray());
    }

    public function testAViewFilteringOnWhatItsListNoLongerHasIsOutdated(): void
    {
        $view = SavedView::made('test.items', self::MEMBER, 'Old', Visibility::Shared, ['match' => 'all', 'conditions' => [['field' => 'gone', 'op' => 'is', 'value' => 1]]], [], null, false, Placement::Primary, 5, new \DateTimeImmutable('2026-11-17'));

        try {
            $this->views->filterOf($view);
            self::fail('The filter was accepted.');
        } catch (SavedViewRefused $refused) {
            self::assertSame(RefusalKind::Conflict, $refused->kind());
            self::assertSame('view_outdated', $refused->reason());
            self::assertNull($refused->field());
            self::assertStringStartsWith('The view filters on what its list no longer has (', $refused->getMessage());
        }
    }

    public function testAViewOfAListThatIsGoneIsRefusedByItsId(): void
    {
        $view = SavedView::made('test.gone', self::MEMBER, 'Old', Visibility::Shared, ['match' => 'all', 'conditions' => []], [], null, false, Placement::Primary, 5, new \DateTimeImmutable('2026-11-17'));

        $this->assertRefusedBy('list_unknown', 'viewId', fn (): mixed => $this->views->filterOf($view));
    }

    public function testAnUnknownListIsRefusedWithItsFieldAndMessage(): void
    {
        try {
            $this->views->list('test.nothing');
            self::fail('The list was found.');
        } catch (SavedViewRefused $refused) {
            self::assertSame('list_unknown', $refused->reason());
            self::assertSame('listKey', $refused->field());
            self::assertSame('There is no list "test.nothing". Find the lists with foresi_list_lists.', $refused->getMessage());
        }
        self::assertSame('test.items', $this->views->list('test.items')->key);
    }

    public function testTheRefusalsCarryTheirMessageKindAndNoDetails(): void
    {
        $notFound = SavedViewRefused::notFound();
        self::assertSame('There is no view with this id that you can see. Find views with foresi_list_views.', $notFound->getMessage());
        self::assertSame('not_found', $notFound->reason());
        self::assertSame(RefusalKind::NotFound, $notFound->kind());
        self::assertNull($notFound->field());
        self::assertSame([], $notFound->details());

        $forbidden = SavedViewRefused::forbidden('Not for you.');
        self::assertSame('Not for you.', $forbidden->getMessage());
        self::assertSame('forbidden', $forbidden->reason());

        $conflict = SavedViewRefused::conflict('clash', 'Two at once.');
        self::assertSame('Two at once.', $conflict->getMessage());
        self::assertSame(RefusalKind::Conflict, $conflict->kind());

        $invalid = SavedViewRefused::invalid('bad', 'name', 'Wrong.');
        self::assertSame('Wrong.', $invalid->getMessage());
        self::assertSame('name', $invalid->field());
        self::assertSame(RefusalKind::Invalid, $invalid->kind());
    }

    public function testTheInputsDescribeTheirFieldsToTheApi(): void
    {
        self::assertSame(80, $this->attribute(CreateViewInput::class, 'listKey')->maxLength);
        self::assertSame(80, $this->attribute(RestoreDefaultViewsInput::class, 'listKey')->maxLength);
        self::assertSame(SavedView::NAME_MAX_LENGTH, $this->attribute(CreateViewInput::class, 'name')->maxLength);
        self::assertFalse(new \ReflectionClass(CreateViewInput::class)->getConstructor()?->getParameters()[6]->getDefaultValue());
    }

    /**
     * @param class-string $class
     */
    private function attribute(string $class, string $parameter): ApiField
    {
        foreach (new \ReflectionClass($class)->getConstructor()?->getParameters() ?? [] as $candidate) {
            if ($candidate->getName() === $parameter) {
                return $candidate->getAttributes(ApiField::class)[0]->newInstance();
            }
        }

        throw new \LogicException('No such parameter.');
    }

    private function assertRefusedBy(string $reason, string $field, \Closure $call): void
    {
        try {
            $call();
            self::fail('It was allowed.');
        } catch (SavedViewRefused $refused) {
            self::assertSame($reason, $refused->reason());
            self::assertSame($field, $refused->field());
        }
    }

    /**
     * @param list<mixed> $conditions
     */
    private function input(string $name, string $visibility, array $conditions = []): CreateViewInput
    {
        return new CreateViewInput('test.items', $name, $visibility, $conditions);
    }

    private function assertRefused(RefusalKind $kind, \Closure $call): void
    {
        try {
            $call();
            self::fail('It was allowed.');
        } catch (SavedViewRefused $refused) {
            self::assertSame($kind, $refused->kind(), $refused->getMessage());
        }
    }
}

final class FakeActor implements ActorRole
{
    public function __construct(public ?string $userId, public bool $staff, public bool $owner)
    {
    }

    public function userId(): ?string
    {
        return $this->userId;
    }

    public function isOwner(): bool
    {
        return $this->staff && $this->owner;
    }

    public function isStaff(): bool
    {
        return $this->staff;
    }

    public function isStaffMember(string $userId): bool
    {
        return $this->staff;
    }
}

final class InMemoryViews implements SavedViewStore
{
    public int $flushes = 0;

    /** @var array<string, SavedView> */
    private array $views = [];

    public function ofList(string $listKey): array
    {
        $views = array_values(array_filter($this->views, static fn (SavedView $view): bool => $view->listKey() === $listKey));
        usort($views, static fn (SavedView $a, SavedView $b): int => $a->position() <=> $b->position());

        return $views;
    }

    public function bySystemKey(string $systemKey): ?SavedView
    {
        foreach ($this->views as $view) {
            if ($view->systemKey() === $systemKey) {
                return $view;
            }
        }

        return null;
    }

    public function get(string $id): ?SavedView
    {
        return $this->views[$id] ?? null;
    }

    public function remove(SavedView $view): void
    {
        unset($this->views[$view->id()]);
    }

    public function add(SavedView $view): void
    {
        $this->views[$view->id()] = $view;
    }

    public function flush(): void
    {
        ++$this->flushes;
    }
}
