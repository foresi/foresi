<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\Work\Application\View;

use Foresi\Shared\Application\View\FieldType;
use Foresi\Shared\Application\View\ListDefinition;
use Foresi\Work\Application\View\ProjectLists;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * The projects index ships All and Archived, the tabs it had (saved views D12), and
 * none of them counts (D9); the fields of the filter sheet fit a project (D11).
 */
final class ProjectListsTest extends KernelTestCase
{
    public function testTheListShipsAllAndArchivedWithoutCounts(): void
    {
        $lists = iterator_to_array($this->lists()->lists(), false);
        self::assertCount(1, $lists);
        $list = $lists[0];
        self::assertSame('work.projects', $list->key);

        $defaults = [];
        foreach ($list->defaults as $default) {
            $defaults[$default->slug] = [$default->name, $default->filter->toArray()['conditions'], $default->showCount, $default->position];
        }
        self::assertSame([
            ListDefinition::ALL => ['work.projects.tab.all', [], false, 0],
            'archived' => ['work.projects.tab.archived', [['field' => 'archived', 'op' => 'is', 'value' => true]], false, 1],
        ], $defaults);
    }

    public function testTheFieldsFitAProject(): void
    {
        $fields = $this->lists()->list()->fields;

        // The client is Work's Contacts integration's (tests/Work/Integration/Contacts); other modules' fields have a prefix.
        $counts = ['open_tasks', 'overdue_tasks', 'unassigned_tasks', 'next_due'];
        self::assertSame(['archived', 'visibility', ...$counts, 'name'], array_values(array_filter(array_keys($fields), static fn (string $key): bool => 'client' !== $key && (!str_contains($key, '_') || \in_array($key, $counts, true)))));
        self::assertSame(FieldType::Boolean, $fields['archived']->type);
        self::assertSame('work.projects.field.archived', $fields['archived']->label);
        self::assertSame(FieldType::Enum, $fields['visibility']->type);
        self::assertSame(['organisation', 'chosen'], $fields['visibility']->options);
        self::assertSame(['organisation' => 'work.sharing.visibility.organisation', 'chosen' => 'work.sharing.visibility.chosen'], $fields['visibility']->optionLabels);
        self::assertSame('work.projects.field.visibility', $fields['visibility']->label);
        foreach (['open_tasks', 'overdue_tasks', 'unassigned_tasks'] as $count) {
            self::assertSame(FieldType::Number, $fields[$count]->type);
            self::assertSame('work.projects.field.'.$count, $fields[$count]->label);
            self::assertSame(['eq', 'ne', 'lt', 'le', 'gt', 'ge', 'between'], $fields[$count]->operators, 'A count is never empty.');
        }
        self::assertSame(FieldType::Date, $fields['next_due']->type);
        self::assertSame('work.projects.field.next_due', $fields['next_due']->label);
        self::assertContains('is_empty', $fields['next_due']->operators);
    }

    public function testTheListIsBuiltOnce(): void
    {
        self::assertSame($this->lists()->list(), $this->lists()->list());
    }

    private function lists(): ProjectLists
    {
        return self::getContainer()->get(ProjectLists::class);
    }
}
