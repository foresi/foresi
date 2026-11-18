<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\Shared\Application\View;

use Foresi\Shared\Application\ActorRole;
use Foresi\Shared\Application\RefusalKind;
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
                yield new ListDefinition('test.items', [new Field('paid', FieldType::Boolean, 'Paid'), new Field('due_date', FieldType::Date, 'Due date')], [
                    new DefaultView('all', 'All'),
                    new DefaultView('paid', 'Paid', ViewFilter::all(new Condition('paid', 'is', true)), position: 1),
                ]);
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
        self::assertGreaterThan(1, $view->position(), 'A new view comes after the shipped ones.');

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
        $this->assertRefused(RefusalKind::NotFound, fn () => $this->views->get($view->id()));
        $this->assertRefused(RefusalKind::NotFound, fn () => $this->views->delete(new DeleteViewInput($view->id())));
    }

    public function testASharedViewIsChangedByItsCreatorAndTheOwnersOnly(): void
    {
        $view = $this->views->create($this->input('Team', 'shared'));

        $this->actor->userId = self::OTHER_MEMBER;
        $this->assertRefused(RefusalKind::Forbidden, fn () => $this->views->update(new UpdateViewInput($view->id(), name: 'Mine now')));
        $this->assertRefused(RefusalKind::Forbidden, fn () => $this->views->delete(new DeleteViewInput($view->id())));

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

        $this->assertRefused(RefusalKind::Forbidden, fn () => $this->views->update(new UpdateViewInput($paid->id(), name: 'Settled')));

        $this->actor->userId = self::OWNER;
        $this->actor->owner = true;
        $this->views->update(new UpdateViewInput($paid->id(), name: 'Settled', hidden: true));
        self::assertSame('Settled', $paid->name());
        self::assertTrue($paid->isHidden());
        self::assertFalse($paid->isPristine());

        $this->assertRefused(RefusalKind::Conflict, fn () => $this->views->delete(new DeleteViewInput($paid->id())));
        $this->assertRefused(RefusalKind::Invalid, fn () => $this->views->update(new UpdateViewInput($paid->id(), visibility: 'private')));

        $this->views->restoreDefaults(new RestoreDefaultViewsInput('test.items'));
        self::assertSame('Paid', $paid->name());
        self::assertFalse($paid->isHidden());
        self::assertTrue($paid->isPristine());
    }

    public function testOnlyOwnersRestoreTheDefaults(): void
    {
        $this->assertRefused(RefusalKind::Forbidden, fn () => $this->views->restoreDefaults(new RestoreDefaultViewsInput('test.items')));
    }

    public function testGuestsHaveNoViews(): void
    {
        $this->actor->staff = false;

        $this->assertRefused(RefusalKind::Forbidden, fn () => $this->views->visible('test.items'));
        $this->assertRefused(RefusalKind::Forbidden, fn () => $this->views->create($this->input('Guest', 'private')));
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
        yield 'bad density' => [static fn (): CreateViewInput => new CreateViewInput('test.items', 'Broken', density: 'airy'), 'density'];
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

    public function testChangingTheDensityKeepsTheColumns(): void
    {
        $view = $this->views->create(new CreateViewInput('test.items', 'Cols', columns: ['number', ['key' => 'customer', 'width' => 240]]));

        $this->views->update(new UpdateViewInput($view->id(), density: 'dense'));

        self::assertSame(['columns' => [['key' => 'number'], ['key' => 'customer', 'width' => 240]], 'density' => 'dense'], $view->display());
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
    }
}
