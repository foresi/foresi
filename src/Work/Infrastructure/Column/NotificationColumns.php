<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Work\Infrastructure\Column;

use Foresi\Shared\Domain\View\AttributeType;
use Foresi\Ui\Contract\Attribute;
use Foresi\Ui\Contract\DataSource;
use Foresi\Work\Application\View\NotificationLists;

/**
 * The columns of the inbox; each cell renders one NotificationCells. The task names
 * the row and stays; the task, the project and the event give way, cut short with
 * their full text in a title. They are the columns the inbox had.
 */
final readonly class NotificationColumns implements DataSource
{
    public const string LIST = NotificationLists::KEY;

    public function attributes(string $list): iterable
    {
        if (self::LIST !== $list) {
            return [];
        }

        return [
            new Attribute('task', 'work.notifications.column.task', AttributeType::Text, static fn (NotificationCells $row): string => $row->task, defaultVisible: true, defaultPosition: 10, cell: static fn (NotificationCells $row): string => $row->task, locked: true, truncate: true, filterable: true, filter: 't.title'),
            new Attribute('project', 'work.task.field.project', AttributeType::Text, static fn (NotificationCells $row): string => $row->project, defaultVisible: true, defaultPosition: 20, cell: static fn (NotificationCells $row): string => $row->project, phone: false, truncate: true, filterable: true, filter: 'p.name'),
            new Attribute('event', 'work.notifications.column.event', AttributeType::Text, static fn (NotificationCells $row): string => $row->event, defaultVisible: true, defaultPosition: 30, cell: static fn (NotificationCells $row): string => $row->event, phone: false, truncate: true),
            new Attribute('at', 'work.notifications.column.at', AttributeType::DateTime, static fn (NotificationCells $row): string => $row->at, defaultVisible: true, defaultPosition: 40, cell: static fn (NotificationCells $row): string => $row->at, phone: false, filterable: true, filter: 'n.createdAt'),
        ];
    }
}
