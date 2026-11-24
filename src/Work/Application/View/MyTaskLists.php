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
 * My unfinished tasks and the views they ship (spec D12): All, which every list starts
 * with, holds the tasks I am responsible for and those I watch; Assigned to me and
 * Watching are the tabs the list had, each the same tasks as before. The client of the
 * project, the select the list's sheet had, is a field of the filter sheet (D11). The list
 * counts nothing (D9).
 */
final readonly class MyTaskLists implements ListDefinitionSource
{
    public const string KEY = 'work.my_tasks';
    /** How the task is mine: I am responsible for it, or I watch it. */
    public const string INVOLVEMENT = 'involvement';
    public const string CLIENT = 'client';

    public const string RESPONSIBLE = 'responsible';
    public const string WATCHER = 'watcher';

    /** The views, by the ?tab= of the tabs they replaced. */
    public const string ASSIGNED = 'assigned';
    public const string WATCHING = 'watching';

    /** What a client reference points to: the builder picks one with the contact picker. */
    public const string CONTACT = 'contact';

    public function lists(): iterable
    {
        yield self::list();
    }

    public static function list(): ListDefinition
    {
        return new ListDefinition(self::KEY, [
            new Field(self::INVOLVEMENT, FieldType::Enum, 'work.my_tasks.involvement', options: [self::RESPONSIBLE, self::WATCHER], optionLabels: [
                self::RESPONSIBLE => 'work.my_tasks.involvement_option.'.self::RESPONSIBLE,
                self::WATCHER => 'work.my_tasks.involvement_option.'.self::WATCHER,
            ]),
            new Field(self::CLIENT, FieldType::Reference, 'work.my_tasks.client', source: self::CONTACT),
        ], [
            new DefaultView(ListDefinition::ALL, 'work.my_tasks.tab.all', showCount: false, position: 0),
            new DefaultView(self::ASSIGNED, 'work.my_tasks.tab.assigned', ViewFilter::all(new Condition(self::INVOLVEMENT, 'is', self::RESPONSIBLE)), showCount: false, position: 1),
            new DefaultView(self::WATCHING, 'work.my_tasks.tab.watching', ViewFilter::all(new Condition(self::INVOLVEMENT, 'is', self::WATCHER)), showCount: false, position: 2),
        ], [self::CLIENT]);
    }
}
