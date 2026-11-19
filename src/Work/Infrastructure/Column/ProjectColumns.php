<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Work\Infrastructure\Column;

use Foresi\Shared\Domain\View\AttributeType;
use Foresi\Ui\Contract\Attribute;
use Foresi\Ui\Contract\DataSource;
use Foresi\Work\Application\View\ProjectLists;

/**
 * The columns of the projects index, the ones it showed before saved views; each cell
 * renders one ProjectIndexRow. The name leads and stays; the server sorts by it alone.
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
            new Attribute('name', $label('name'), AttributeType::Text, static fn (ProjectIndexRow $row): string => $row->name, sort: 'p.name', defaultVisible: true, defaultPosition: 10, cell: static fn (ProjectIndexRow $row): string => $row->name, locked: true, truncate: true),
            new Attribute('client', $label('client'), AttributeType::Reference, static fn (ProjectIndexRow $row): ?string => $row->client, defaultVisible: true, defaultPosition: 20, cell: static fn (ProjectIndexRow $row): ?array => null === $row->clientHref ? null : ['links' => [['text' => $row->client ?? '', 'href' => $row->clientHref]]], phone: false, truncate: true),
            new Attribute('open', $label('open'), AttributeType::Number, static fn (ProjectIndexRow $row): int => $row->openTasks, defaultVisible: true, defaultPosition: 30, cell: static fn (ProjectIndexRow $row): string => (string) $row->openTasks),
            new Attribute('due', $label('due'), AttributeType::Text, static fn (ProjectIndexRow $row): ?string => $row->due, defaultVisible: true, defaultPosition: 40, cell: static fn (ProjectIndexRow $row): ?string => $row->due, phone: false),
        ];
    }
}
