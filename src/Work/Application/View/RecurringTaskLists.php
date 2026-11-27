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
use Foresi\Shared\Application\View\ListFields;
use Foresi\Shared\Application\View\ViewFilter;

/**
 * The recurring tasks as a list with views (docs/design/list-pages.md, rule 8), as the
 * recurring invoices are: All, then Active, Paused and Ended.
 */
final class RecurringTaskLists implements ListDefinitionSource
{
    public const string KEY = 'work.recurring_tasks';
    public const string TITLE = 'title';
    public const string PROJECT = 'project';
    public const string STATE = 'state';
    public const string NEXT = 'next';

    private ?ListDefinition $list = null;

    public function __construct(private readonly ListFields $fields)
    {
    }

    public function lists(): iterable
    {
        yield $this->list();
    }

    public function list(): ListDefinition
    {
        $state = static fn (string $value): ViewFilter => ViewFilter::all(new Condition(self::STATE, 'is', $value));

        return $this->list ??= new ListDefinition(self::KEY, $this->fields->of(self::KEY, [
            new Field(self::TITLE, FieldType::Text, 'work.recurring.field.title'),
            new Field(self::PROJECT, FieldType::Text, 'work.recurring.field.project'),
            new Field(self::STATE, FieldType::Enum, 'work.recurring.field.state', options: ['active', 'paused', 'ended'], optionLabels: ['active' => 'work.recurring.state.active', 'paused' => 'work.recurring.state.paused', 'ended' => 'work.recurring.state.ended']),
            new Field(self::NEXT, FieldType::Date, 'work.recurring.field.next'),
        ]), [
            new DefaultView(ListDefinition::ALL, 'work.recurring.tab.all', showCount: false, position: 0),
            new DefaultView('active', 'work.recurring.state.active', $state('active'), position: 1),
            new DefaultView('paused', 'work.recurring.state.paused', $state('paused'), position: 2),
            new DefaultView('ended', 'work.recurring.state.ended', $state('ended'), position: 3),
        ]);
    }
}
