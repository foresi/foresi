<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\Work\Application\View;

use Foresi\Shared\Application\View\DefaultView;
use Foresi\Shared\Application\View\FieldType;
use Foresi\Shared\Domain\View\Placement;
use Foresi\Work\Application\View\ProjectTaskLists;
use PHPUnit\Framework\TestCase;

/**
 * A project's task list definition (saved views D3, D12): its fields, the filters
 * it had, and the views it ships, none of which counts (D9).
 */
final class ProjectTaskListsTest extends TestCase
{
    public function testItShipsAllMineAndFinishedWithoutCounts(): void
    {
        $list = ProjectTaskLists::list();

        self::assertEquals([$list], iterator_to_array(new ProjectTaskLists()->lists()));
        self::assertSame('work.project_tasks', $list->key);
        self::assertSame(
            [
                ['all', 'work.list.tab.all', [], 0],
                ['mine', 'work.list.tab.mine', [['field' => 'responsible', 'op' => 'is', 'value' => 'me']], 1],
                ['finished', 'work.list.tab.finished', [['field' => 'status', 'op' => 'is', 'value' => 'finished']], 2],
            ],
            array_map(static fn (DefaultView $view): array => [$view->slug, $view->name, $view->filter->toArray()['conditions'], $view->position], $list->defaults),
        );
        foreach ($list->defaults as $view) {
            self::assertFalse($view->showCount);
            self::assertSame(Placement::Primary, $view->placement);
        }
    }

    public function testItsFieldsAreTheFiltersTheListHad(): void
    {
        $fields = ProjectTaskLists::list()->fields;

        self::assertSame(['responsible', 'status', 'due'], array_keys($fields));
        self::assertSame(FieldType::Reference, $fields['responsible']->type);
        self::assertSame('person', $fields['responsible']->source);
        self::assertSame('work.filter.responsible', $fields['responsible']->label);
        self::assertSame(['open' => 'work.filter.status_open', 'finished' => 'work.filter.status_finished'], $fields['status']->optionLabels);
        self::assertSame('work.filter.status', $fields['status']->label);
        self::assertSame(['overdue' => 'work.filter.due_overdue', 'today' => 'work.filter.due_today', 'next_7_days' => 'work.filter.due_next_7_days', 'none' => 'work.filter.due_none'], $fields['due']->optionLabels);
        self::assertSame('work.filter.due', $fields['due']->label);
    }
}
