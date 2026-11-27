<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\Work\Application\View;

use Foresi\Shared\Application\View\DefaultView;
use Foresi\Shared\Application\View\Field;
use Foresi\Shared\Application\View\FieldType;
use Foresi\Shared\Application\View\ListFields;
use Foresi\Shared\Domain\View\Placement;
use Foresi\Work\Application\View\TaskLists;
use Foresi\Work\Contract\ProjectClients;
use PHPUnit\Framework\TestCase;

/**
 * The list of all tasks across projects (plan 37.1): its fields, the views it ships, none
 * counted, the quick filters it pins, and how a view groups and sorts when it keeps neither;
 * the client only where the address book is installed.
 */
final class TaskListsTest extends TestCase
{
    public function testItShipsAllMineAndFreelosFiltersInTheirOrderWithoutCounts(): void
    {
        $list = $this->lists()->list();

        self::assertSame('work.tasks', $list->key);
        self::assertSame(
            [
                ['all', 'work.tasks.tab.all', [], 0],
                ['mine', 'work.tasks.tab.mine', [['field' => 'responsible', 'op' => 'is', 'value' => 'me']], 1],
                ['overdue', 'work.tasks.tab.overdue', [['field' => 'due', 'op' => 'is', 'value' => 'overdue']], 2],
                ['unassigned', 'work.tasks.tab.unassigned', [['field' => 'responsible', 'op' => 'is_empty']], 3],
                ['high', 'work.tasks.tab.high', [['field' => 'priority', 'op' => 'is_any_of', 'value' => ['high', 'urgent']]], 4],
                ['no_due', 'work.tasks.tab.no_due', [['field' => 'due', 'op' => 'is', 'value' => 'none']], 5],
            ],
            array_map(static fn (DefaultView $view): array => [$view->slug, $view->name, $view->filter->toArray()['conditions'], $view->position], $list->defaults),
        );
        foreach ($list->defaults as $view) {
            self::assertFalse($view->showCount);
            self::assertSame(Placement::Primary, $view->placement);
        }
    }

    public function testItPinsTheProjectWhoIsResponsibleThePriorityAndWhenItIsDue(): void
    {
        self::assertSame(['project', 'responsible', 'priority', 'due'], $this->lists()->list()->pinned);
        self::assertSame(['project', 'responsible', 'priority', 'due'], TaskLists::plain()->pinned);
    }

    public function testTheListIsBuiltOnceAndListedAlone(): void
    {
        $lists = $this->lists();

        self::assertSame($lists->list(), $lists->list());
        self::assertSame([$lists->list()], iterator_to_array($lists->lists()));
        self::assertEquals(TaskLists::plain(), $lists->list());
    }

    public function testItsFieldsAreWhatTheSmallFirmToolsFilterBy(): void
    {
        $fields = $this->lists()->list()->fields;

        self::assertSame(
            [
                'title' => 'work.task.field.title',
                'project' => 'work.task.field.project',
                'client' => 'work.tasks.field.client',
                'responsible' => 'work.filter.responsible',
                'involvement' => 'work.my_tasks.involvement',
                'status_type' => 'work.tasks.field.status_type',
                'priority' => 'work.task.field.priority',
                'labels' => 'work.task.field.labels',
                'due' => 'work.filter.due',
                'due_on' => 'work.tasks.field.due_on',
                'created_by' => 'work.tasks.field.created_by',
                'created' => 'work.tasks.field.created',
                'updated' => 'work.tasks.field.updated',
            ],
            array_map(static fn (Field $field): string => $field->label, $fields),
        );
        self::assertSame(
            [
                'title' => [FieldType::Text, null],
                'project' => [FieldType::Reference, 'project'],
                'client' => [FieldType::Reference, 'contact'],
                'responsible' => [FieldType::Reference, 'person'],
                'involvement' => [FieldType::Enum, null],
                'status_type' => [FieldType::Enum, null],
                'priority' => [FieldType::Enum, null],
                'labels' => [FieldType::Reference, 'label'],
                'due' => [FieldType::Enum, null],
                'due_on' => [FieldType::Date, null],
                'created_by' => [FieldType::Reference, 'person'],
                'created' => [FieldType::Date, null],
                'updated' => [FieldType::Date, null],
            ],
            array_map(static fn (Field $field): array => [$field->type, $field->source], $fields),
        );
    }

    public function testItsEnumsOfferEveryValueUnderItsOwnLabel(): void
    {
        $fields = $this->lists()->list()->fields;

        self::assertSame(['responsible', 'watcher'], $fields['involvement']->options);
        self::assertSame(['responsible' => 'work.my_tasks.involvement_option.responsible', 'watcher' => 'work.my_tasks.involvement_option.watcher'], $fields['involvement']->optionLabels);
        self::assertSame(['open', 'active', 'done', 'closed'], $fields['status_type']->options);
        self::assertSame(['open' => 'work.settings.type.open', 'active' => 'work.settings.type.active', 'done' => 'work.settings.type.done', 'closed' => 'work.settings.type.closed'], $fields['status_type']->optionLabels);
        self::assertSame(['none', 'low', 'medium', 'high', 'urgent'], $fields['priority']->options);
        self::assertSame(['none' => 'work.priority.none', 'low' => 'work.priority.low', 'medium' => 'work.priority.medium', 'high' => 'work.priority.high', 'urgent' => 'work.priority.urgent'], $fields['priority']->optionLabels);
        self::assertSame(['overdue', 'today', 'next_7_days', 'none'], $fields['due']->options);
        self::assertSame(['overdue' => 'work.filter.due_overdue', 'today' => 'work.filter.due_today', 'next_7_days' => 'work.filter.due_next_7_days', 'none' => 'work.filter.due_none'], $fields['due']->optionLabels);
    }

    public function testMineGroupsByWhenItIsDueAndEveryOtherViewNotAtAll(): void
    {
        self::assertSame(['due', 'none', 'client', 'project', 'responsible', 'priority', 'status_type'], TaskLists::groupsOf('mine'));
        self::assertSame(['none', 'client', 'project', 'responsible', 'priority', 'status_type', 'due'], TaskLists::groupsOf('overdue'));
        self::assertSame(['none', 'client', 'project', 'responsible', 'priority', 'status_type', 'due'], TaskLists::groupsOf(null));
    }

    public function testMineSortsByTheHighestPriorityAndEveryOtherViewByWhenItIsDue(): void
    {
        self::assertSame('-priority', TaskLists::sortOf('mine'));
        self::assertSame('due', TaskLists::sortOf('high'));
        self::assertSame('due', TaskLists::sortOf(null));
    }

    public function testASortOfTheListIsKeptBackwardsTooAndAnyOtherFallsBack(): void
    {
        self::assertSame('-updated', TaskLists::sort('-updated', 'due'));
        self::assertSame('title', TaskLists::sort('title', 'due'));
        self::assertSame('due', TaskLists::sort('amount', 'due'));
        self::assertSame('-priority', TaskLists::sort('', '-priority'));
    }

    public function testWithoutTheAddressBookAProjectHasNoClientToFilterOrGroupBy(): void
    {
        $lists = new TaskLists(new ListFields([]));

        self::assertFalse($lists->hasClients());
        self::assertArrayNotHasKey('client', $lists->list()->fields);
        self::assertEquals(TaskLists::plain(false), $lists->list());
        self::assertSame(['project', 'responsible', 'priority', 'due'], $lists->list()->pinned);
        self::assertSame(['due', 'none', 'project', 'responsible', 'priority', 'status_type'], TaskLists::groupsOf('mine', false));
        self::assertSame(['none', 'project', 'responsible', 'priority', 'status_type', 'due'], TaskLists::groupsOf(null, false));
    }

    private function lists(): TaskLists
    {
        return new TaskLists(new ListFields([]), self::clients());
    }

    /**
     * The address book as Work's Contacts integration answers it, without its pages.
     */
    private static function clients(): ProjectClients
    {
        return new class implements ProjectClients {
            public function pickerUrl(): string
            {
                return '/projects/clients';
            }

            public function name(string $id): ?string
            {
                return null;
            }

            public function pageUrl(string $id): ?string
            {
                return null;
            }
        };
    }
}
