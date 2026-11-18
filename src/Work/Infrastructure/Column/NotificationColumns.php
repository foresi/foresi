<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Work\Infrastructure\Column;

use Foresi\Ui\Contract\Column;
use Foresi\Ui\Contract\ListColumns;
use Foresi\Work\Application\View\NotificationLists;

/**
 * The columns of the inbox; each cell renders one NotificationCells. The task names
 * the row and stays; the task, the project and the event give way, cut short with
 * their full text in a title. They are the columns the inbox had.
 */
final readonly class NotificationColumns implements ListColumns
{
    public const string LIST = NotificationLists::KEY;

    public function columns(string $list): iterable
    {
        if (self::LIST !== $list) {
            return [];
        }

        return [
            new Column('task', 'work.notifications.column.task', static fn (NotificationCells $row): string => $row->task, defaultPosition: 10, locked: true, truncate: true),
            new Column('project', 'work.task.field.project', static fn (NotificationCells $row): string => $row->project, defaultPosition: 20, phone: false, truncate: true),
            new Column('event', 'work.notifications.column.event', static fn (NotificationCells $row): string => $row->event, defaultPosition: 30, phone: false, truncate: true),
            new Column('at', 'work.notifications.column.at', static fn (NotificationCells $row): string => $row->at, defaultPosition: 40, phone: false),
        ];
    }
}
