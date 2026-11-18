<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Work\Infrastructure\Column;

use Foresi\Ui\Contract\Column;
use Foresi\Ui\Contract\ListColumns;
use Foresi\Work\Application\View\TimeReportLists;

/**
 * The columns of the entries of the time report, the ones it showed before saved views;
 * each cell renders one TimeReportEntryRow. The work leads and stays; the entries keep
 * their one order, newest first, so no column sorts.
 */
final readonly class TimeReportColumns implements ListColumns
{
    public const string LIST = TimeReportLists::KEY;

    public function columns(string $list): iterable
    {
        if (self::LIST !== $list) {
            return [];
        }

        return [
            new Column('title', 'work.time.report.column.work', static fn (TimeReportEntryRow $row): string => $row->title, defaultPosition: 10, locked: true, truncate: true),
            new Column('project', 'work.task.field.project', static fn (TimeReportEntryRow $row): string => $row->project, defaultPosition: 20, phone: false, truncate: true),
            new Column('person', 'work.time.report.column.person', static fn (TimeReportEntryRow $row): string => $row->person, defaultPosition: 30, phone: false),
            new Column('date', 'work.time.report.column.date', static fn (TimeReportEntryRow $row): string => $row->date, defaultPosition: 40, phone: false),
            new Column('note', 'work.task.field.note', static fn (TimeReportEntryRow $row): ?string => $row->note, defaultPosition: 50, phone: false, wide: true, truncate: true),
            new Column('billable', 'work.time.billable', static fn (TimeReportEntryRow $row): ?array => $row->billable, defaultPosition: 60, phone: false),
            new Column('minutes', 'work.time.report.column.time', static fn (TimeReportEntryRow $row): string => $row->time, defaultPosition: 70, align: 'right'),
        ];
    }
}
