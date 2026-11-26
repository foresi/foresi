<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\Timesheet\Application\View;

use Foresi\Shared\Application\View\FieldType;
use Foresi\Shared\Application\View\ListDefinition;
use Foresi\Timesheet\Application\View\TimeReportLists;
use PHPUnit\Framework\TestCase;

/**
 * The time report ships All and the two periods its filter offered, This month and
 * Last month, as relative dates (saved views D5, D12); none counts (D9). The fields of
 * the filter sheet are the filters the report had (D11).
 */
final class TimeReportListsTest extends TestCase
{
    public function testTheDayAndThePersonArePinnedAsQuickFilters(): void
    {
        self::assertSame(['worked_on', 'person'], TimeReportLists::list()->pinned);
    }

    public function testTheListShipsAllAndThePeriodsWithoutCounts(): void
    {
        $lists = iterator_to_array(new TimeReportLists()->lists(), false);
        self::assertCount(1, $lists);
        $list = $lists[0];
        self::assertSame('work.time_report', $list->key);

        $defaults = [];
        foreach ($list->defaults as $default) {
            $defaults[$default->slug] = [$default->name, $default->filter->toArray()['conditions'], $default->showCount, $default->position];
        }
        self::assertSame([
            ListDefinition::ALL => ['timesheet.time.report.view.all', [], false, 0],
            'this_month' => ['timesheet.time.report.view.this_month', [['field' => 'worked_on', 'op' => 'is', 'value' => ['relative' => 'this_month']]], false, 1],
            'last_month' => ['timesheet.time.report.view.last_month', [['field' => 'worked_on', 'op' => 'is', 'value' => ['relative' => 'last_month']]], false, 2],
        ], $defaults);
    }

    public function testTheFieldsAreTheFiltersTheReportHad(): void
    {
        $fields = TimeReportLists::list()->fields;

        self::assertSame(['worked_on', 'project', 'client', 'person', 'billable', 'invoiced'], array_keys($fields));
        self::assertSame(FieldType::Date, $fields['worked_on']->type);
        self::assertSame(['is', 'before', 'after', 'on_or_before', 'on_or_after', 'between'], $fields['worked_on']->operators, 'every entry has its day');
        self::assertSame('timesheet.time.report.column.date', $fields['worked_on']->label);
        foreach (['project' => ['project', 'work.task.field.project'], 'person' => ['person', 'timesheet.time.report.column.person']] as $key => [$source, $label]) {
            self::assertSame(FieldType::Reference, $fields[$key]->type);
            self::assertSame($source, $fields[$key]->source);
            self::assertSame($label, $fields[$key]->label);
            self::assertSame(['is', 'is_not', 'is_any_of'], $fields[$key]->operators, 'every entry has its '.$key);
        }
        self::assertSame(FieldType::Reference, $fields['client']->type);
        self::assertSame('contact', $fields['client']->source);
        self::assertSame('work.project.field.client', $fields['client']->label);
        self::assertContains('is_empty', $fields['client']->operators, 'internal work has no client');
        self::assertSame(FieldType::Boolean, $fields['billable']->type);
        self::assertSame('timesheet.time.billable', $fields['billable']->label);
        self::assertSame(FieldType::Boolean, $fields['invoiced']->type);
        self::assertSame('timesheet.time.invoiced', $fields['invoiced']->label);
    }

    public function testAPeriodIsTheDayAsARelativeDate(): void
    {
        self::assertSame(['field' => 'worked_on', 'op' => 'is', 'value' => ['relative' => 'last_month']], TimeReportLists::period('last_month')->toArray());
    }
}
