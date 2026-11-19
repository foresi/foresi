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
 * none of them counts (D9); the fields of "+ Filter" fit a project (D11).
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

        self::assertSame(['archived', 'client', 'visibility', 'billable', 'name'], array_keys($fields));
        self::assertSame(FieldType::Boolean, $fields['archived']->type);
        self::assertSame('work.projects.field.archived', $fields['archived']->label);
        self::assertSame(FieldType::Reference, $fields['client']->type);
        self::assertSame('contact', $fields['client']->source);
        self::assertSame('work.projects.column.client', $fields['client']->label);
        self::assertSame(FieldType::Enum, $fields['visibility']->type);
        self::assertSame(['organisation', 'chosen'], $fields['visibility']->options);
        self::assertSame(['organisation' => 'work.sharing.visibility.organisation', 'chosen' => 'work.sharing.visibility.chosen'], $fields['visibility']->optionLabels);
        self::assertSame('work.projects.field.visibility', $fields['visibility']->label);
        self::assertSame(FieldType::Boolean, $fields['billable']->type);
        self::assertSame('work.projects.field.billable', $fields['billable']->label);
    }

    private function lists(): ProjectLists
    {
        return self::getContainer()->get(ProjectLists::class);
    }
}
