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
use Foresi\Work\Application\DueBucket;

/**
 * The task list of a project and the views it ships. One list serves every project:
 * a view keeps who is responsible, the status and the due date, which mean the same
 * in each, so a view saved on one project shows on all of them.
 *
 * The filters the list had (responsible, status, due) are fields of "+ Filter"
 * (spec D11). Finished tasks are left out unless a condition asks about the status,
 * as the list left them out by default and as ClickUp hides closed tasks and Asana
 * shows incomplete ones: All holds no condition, as every list's All, and shows the
 * unfinished tasks, which was the list's default.
 */
final readonly class ProjectTaskLists implements ListDefinitionSource
{
    public const string KEY = 'work.project_tasks';
    public const string RESPONSIBLE = 'responsible';
    public const string STATUS = 'status';
    public const string DUE = 'due';

    /** The status values: a task not finished yet, or finished. */
    public const string OPEN = 'open';
    public const string FINISHED = 'finished';

    /** A responsible value that stands for the person looking, whoever that is. */
    public const string ME = 'me';

    public const string MINE = 'mine';
    public const string DONE = 'finished';

    /** What a responsible reference points to: a person of the organisation. */
    public const string PERSON = 'person';

    public function lists(): iterable
    {
        yield self::list();
    }

    public static function list(): ListDefinition
    {
        return new ListDefinition(self::KEY, self::fields(), self::defaults());
    }

    /**
     * @return list<Field>
     */
    private static function fields(): array
    {
        $statuses = [self::OPEN, self::FINISHED];
        $dues = array_column(DueBucket::cases(), 'value');

        return [
            new Field(self::RESPONSIBLE, FieldType::Reference, 'work.filter.responsible', source: self::PERSON),
            new Field(self::STATUS, FieldType::Enum, 'work.filter.status', options: $statuses, optionLabels: self::labels('work.filter.status_', $statuses)),
            new Field(self::DUE, FieldType::Enum, 'work.filter.due', options: $dues, optionLabels: self::labels('work.filter.due_', $dues)),
        ];
    }

    /**
     * @param list<string> $values
     *
     * @return array<string, string>
     */
    private static function labels(string $prefix, array $values): array
    {
        return array_combine($values, array_map(static fn (string $value): string => $prefix.$value, $values));
    }

    /**
     * No view counts (spec D9): the list never counted.
     *
     * @return list<DefaultView>
     */
    private static function defaults(): array
    {
        return [
            new DefaultView(ListDefinition::ALL, 'work.list.tab.all', showCount: false, position: 0),
            new DefaultView(self::MINE, 'work.list.tab.mine', ViewFilter::all(new Condition(self::RESPONSIBLE, 'is', self::ME)), showCount: false, position: 1),
            new DefaultView(self::DONE, 'work.list.tab.finished', ViewFilter::all(new Condition(self::STATUS, 'is', self::FINISHED)), showCount: false, position: 2),
        ];
    }
}
