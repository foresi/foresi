<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Work\Application\View;

use Foresi\Shared\Application\View\Condition;
use Foresi\Shared\Application\View\DefaultView;
use Foresi\Shared\Application\View\Field;
use Foresi\Shared\Application\View\FieldType;
use Foresi\Shared\Application\View\ListDefinition;
use Foresi\Shared\Application\View\ListDefinitionSource;
use Foresi\Shared\Application\View\ViewFilter;

/**
 * The time report and the views it ships (spec D12). Its filter had a period, this
 * month unless another was chosen, so This month is the view the report opens on and
 * Last month the other period it offered; each is the day the time was worked on as a
 * relative date (D5), so a view saved on it stays right after the month turns. All,
 * as on every list, holds no condition: all the time the person sees. The report
 * counted nothing, so no view shows a count (D9).
 *
 * What the totals add up by is the view's display, not a condition (D-display-2).
 */
final readonly class TimeReportLists implements ListDefinitionSource
{
    public const string KEY = 'work.time_report';
    public const string WORKED_ON = 'worked_on';
    public const string PROJECT = 'project';
    public const string CLIENT = 'client';
    public const string PERSON = 'person';
    public const string BILLABLE = 'billable';
    public const string INVOICED = 'invoiced';
    public const string THIS_MONTH = 'this_month';
    public const string LAST_MONTH = 'last_month';

    /** What each reference points to: the builder picks one with that picker. */
    public const string PROJECTS = 'project';
    public const string CONTACTS = 'contact';
    public const string PEOPLE = 'person';

    public function lists(): iterable
    {
        yield self::list();
    }

    public static function list(): ListDefinition
    {
        return new ListDefinition(self::KEY, self::fields(), self::defaults());
    }

    /**
     * The view of a period the report's old address named (?period=this_month).
     */
    public static function period(string $rule): Condition
    {
        return new Condition(self::WORKED_ON, 'is', ['relative' => $rule]);
    }

    /**
     * @return list<Field>
     */
    private static function fields(): array
    {
        // Every entry has its day, project and person; a project may have no client.
        $always = ['is', 'is_not', 'is_any_of'];

        return [
            new Field(self::WORKED_ON, FieldType::Date, 'work.time.report.column.date', ['is', 'before', 'after', 'on_or_before', 'on_or_after', 'between']),
            new Field(self::PROJECT, FieldType::Reference, 'work.task.field.project', $always, source: self::PROJECTS),
            new Field(self::CLIENT, FieldType::Reference, 'work.project.field.client', source: self::CONTACTS),
            new Field(self::PERSON, FieldType::Reference, 'work.time.report.column.person', $always, source: self::PEOPLE),
            new Field(self::BILLABLE, FieldType::Boolean, 'work.time.billable'),
            new Field(self::INVOICED, FieldType::Boolean, 'work.time.invoiced'),
        ];
    }

    /**
     * @return list<DefaultView>
     */
    private static function defaults(): array
    {
        return [
            new DefaultView(ListDefinition::ALL, 'work.time.report.view.all', showCount: false, position: 0),
            new DefaultView(self::THIS_MONTH, 'work.time.report.view.this_month', ViewFilter::all(self::period(self::THIS_MONTH)), showCount: false, position: 1),
            new DefaultView(self::LAST_MONTH, 'work.time.report.view.last_month', ViewFilter::all(self::period(self::LAST_MONTH)), showCount: false, position: 2),
        ];
    }
}
