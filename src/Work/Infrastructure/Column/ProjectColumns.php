<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Work\Infrastructure\Column;

use Foresi\Ui\Contract\Column;
use Foresi\Ui\Contract\ListColumns;
use Foresi\Work\Application\View\ProjectLists;

/**
 * The columns of the projects index, the ones it showed before saved views; each cell
 * renders one ProjectIndexRow. The name leads and stays; the server sorts by it alone.
 */
final readonly class ProjectColumns implements ListColumns
{
    public const string LIST = ProjectLists::KEY;

    public function columns(string $list): iterable
    {
        if (self::LIST !== $list) {
            return [];
        }
        $label = static fn (string $key): string => 'work.projects.column.'.$key;

        return [
            new Column('name', $label('name'), static fn (ProjectIndexRow $row): string => $row->name, defaultPosition: 10, sortKey: 'name', locked: true, truncate: true),
            new Column('client', $label('client'), static fn (ProjectIndexRow $row): ?array => null === $row->clientHref ? null : ['links' => [['text' => $row->client ?? '', 'href' => $row->clientHref]]], defaultPosition: 20, phone: false, truncate: true),
            new Column('open', $label('open'), static fn (ProjectIndexRow $row): string => (string) $row->openTasks, defaultPosition: 30, align: 'right'),
            new Column('due', $label('due'), static fn (ProjectIndexRow $row): ?string => $row->due, defaultPosition: 40, align: 'right', phone: false),
        ];
    }
}
