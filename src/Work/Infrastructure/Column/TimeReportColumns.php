<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Work\Infrastructure\Column;

use Foresi\Shared\Domain\View\AttributeType;
use Foresi\Ui\Contract\Attribute;
use Foresi\Ui\Contract\DataSource;
use Foresi\Work\Application\View\TimeReportLists;

/**
 * The columns of the entries of the time report, the ones it showed before saved views;
 * each cell renders one TimeReportEntryRow. The work leads and stays; the entries keep
 * their one order, newest first, so no column sorts.
 */
final readonly class TimeReportColumns implements DataSource
{
    public const string LIST = TimeReportLists::KEY;

    public function attributes(string $list): iterable
    {
        if (self::LIST !== $list) {
            return [];
        }

        return [
            new Attribute('title', 'work.time.report.column.work', AttributeType::Text, static fn (TimeReportEntryRow $row): string => $row->title, defaultVisible: true, defaultPosition: 10, cell: static fn (TimeReportEntryRow $row): string => $row->title, locked: true, truncate: true),
            new Attribute('project', 'work.task.field.project', AttributeType::Text, static fn (TimeReportEntryRow $row): string => $row->project, defaultVisible: true, defaultPosition: 20, cell: static fn (TimeReportEntryRow $row): string => $row->project, phone: false, truncate: true),
            new Attribute('person', 'work.time.report.column.person', AttributeType::Text, static fn (TimeReportEntryRow $row): string => $row->person, defaultVisible: true, defaultPosition: 30, cell: static fn (TimeReportEntryRow $row): string => $row->person, phone: false),
            new Attribute('date', 'work.time.report.column.date', AttributeType::Text, static fn (TimeReportEntryRow $row): string => $row->date, defaultVisible: true, defaultPosition: 40, cell: static fn (TimeReportEntryRow $row): string => $row->date, phone: false),
            new Attribute('note', 'work.task.field.note', AttributeType::Text, static fn (TimeReportEntryRow $row): ?string => $row->note, defaultVisible: true, defaultPosition: 50, cell: static fn (TimeReportEntryRow $row): ?string => $row->note, phone: false, wide: true, truncate: true),
            new Attribute('billable', 'work.time.billable', AttributeType::Boolean, static fn (TimeReportEntryRow $row): bool => null !== $row->billable, defaultVisible: true, defaultPosition: 60, cell: static fn (TimeReportEntryRow $row): ?array => $row->billable, phone: false),
            new Attribute('minutes', 'work.time.report.column.time', AttributeType::Text, static fn (TimeReportEntryRow $row): string => $row->time, defaultVisible: true, defaultPosition: 70, cell: static fn (TimeReportEntryRow $row): string => $row->time),
        ];
    }
}
