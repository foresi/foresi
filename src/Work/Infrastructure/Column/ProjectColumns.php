<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Work\Infrastructure\Column;

use Foresi\Shared\Domain\View\AttributeType;
use Foresi\Ui\Contract\Attribute;
use Foresi\Ui\Contract\DataSource;
use Foresi\Work\Application\ProjectOrder;
use Foresi\Work\Application\View\ProjectLists;

/**
 * The columns of the projects index; each cell renders one ProjectIndexRow. The name
 * leads and stays. The server sorts by the name, a count or the next due date
 * (ProjectOrder); a count that counts any task opens them in the list of all tasks.
 */
final readonly class ProjectColumns implements DataSource
{
    public const string LIST = ProjectLists::KEY;

    public function attributes(string $list): iterable
    {
        if (self::LIST !== $list) {
            return [];
        }
        $label = static fn (string $key): string => 'work.projects.column.'.$key;

        return [
            new Attribute('name', $label('name'), AttributeType::Text, static fn (ProjectIndexRow $row): string => $row->name, sort: 'p.name', defaultVisible: true, defaultPosition: 10, cell: static fn (ProjectIndexRow $row): string => $row->name, locked: true, truncate: true, filterable: true),
            new Attribute('client', $label('client'), AttributeType::Reference, static fn (ProjectIndexRow $row): ?string => $row->client, defaultVisible: true, defaultPosition: 20, cell: static fn (ProjectIndexRow $row): ?array => null === $row->clientHref ? null : ['links' => [['text' => $row->client ?? '', 'href' => $row->clientHref]]], phone: false, truncate: true),
            new Attribute('open', $label('open'), AttributeType::Number, static fn (ProjectIndexRow $row): int => $row->openTasks, sort: ProjectOrder::OPEN, defaultVisible: true, defaultPosition: 30, cell: static fn (ProjectIndexRow $row): string|array => self::count($row->openTasks, $row->openHref)),
            new Attribute('overdue', $label('overdue'), AttributeType::Number, static fn (ProjectIndexRow $row): int => $row->overdueTasks, sort: ProjectOrder::OVERDUE, defaultVisible: true, defaultPosition: 32, cell: static fn (ProjectIndexRow $row): string|array => self::count($row->overdueTasks, $row->overdueHref)),
            new Attribute('unassigned', $label('unassigned'), AttributeType::Number, static fn (ProjectIndexRow $row): int => $row->unassignedTasks, sort: ProjectOrder::UNASSIGNED, defaultVisible: true, defaultPosition: 34, cell: static fn (ProjectIndexRow $row): string|array => self::count($row->unassignedTasks, $row->unassignedHref), phone: false),
            new Attribute('due', $label('due'), AttributeType::Text, static fn (ProjectIndexRow $row): ?string => $row->due, sort: ProjectOrder::DUE, defaultVisible: true, defaultPosition: 40, cell: static fn (ProjectIndexRow $row): ?string => $row->due, phone: false),
        ];
    }

    /**
     * A count as a chip that opens the tasks it counts, or as a number when there is nothing to open.
     *
     * @return string|array{links: list<array{text: string, href: string}>}
     */
    private static function count(int $count, ?string $href): string|array
    {
        return 0 === $count || null === $href ? (string) $count : ['links' => [['text' => (string) $count, 'href' => $href]]];
    }
}
