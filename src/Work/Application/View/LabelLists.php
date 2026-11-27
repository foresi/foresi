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
 * The labels of the organisation on Settings → Labels as a list with views
 * (docs/design/list-pages.md, rule 8). All shows the labels in use; the trash, which was a
 * page of its own, is the shipped view Trash, as the trash of deals is.
 */
final class LabelLists implements ListDefinitionSource
{
    public const string KEY = 'work.labels';
    public const string NAME = 'name';
    public const string TASKS = 'tasks';
    public const string TRASHED = 'trashed';
    /** The slug of the trash view, the address of the old trash page. */
    public const string TRASH = 'trash';

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
        return $this->list ??= new ListDefinition(self::KEY, $this->fields->of(self::KEY, [
            new Field(self::NAME, FieldType::Text, 'work.labels.field.name'),
            new Field(self::TASKS, FieldType::Number, 'work.labels.column.tasks'),
            new Field(self::TRASHED, FieldType::Boolean, 'work.labels.field.trashed'),
        ]), [
            new DefaultView(ListDefinition::ALL, 'work.labels.tab.all', showCount: false, position: 0),
            new DefaultView(self::TRASH, 'work.labels.tab.trash', ViewFilter::all(new Condition(self::TRASHED, 'is', true)), position: 1),
        ]);
    }
}
