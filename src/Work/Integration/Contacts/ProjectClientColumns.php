<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Work\Integration\Contacts;

use Foresi\Shared\Domain\View\AttributeType;
use Foresi\Ui\Contract\Attribute;
use Foresi\Ui\Contract\DataSource;
use Foresi\Work\Infrastructure\Column\MyTaskLine;
use Foresi\Work\Infrastructure\Column\ProjectColumns;
use Foresi\Work\Infrastructure\Column\ProjectIndexRow;
use Foresi\Work\Infrastructure\Column\TaskColumns;

/**
 * The client's column of the projects index, linked to the contact, and of the list of
 * all tasks, the client of the task's project: only where the address book is installed.
 */
final readonly class ProjectClientColumns implements DataSource
{
    public function attributes(string $list): iterable
    {
        return match ($list) {
            ProjectColumns::LIST => [
                new Attribute('client', 'work.projects.column.client', AttributeType::Reference, static fn (ProjectIndexRow $row): ?string => $row->client, defaultVisible: true, defaultPosition: 20, cell: static fn (ProjectIndexRow $row): ?array => null === $row->clientHref ? null : ['links' => [['text' => $row->client ?? '', 'href' => $row->clientHref]]], phone: false, truncate: true),
            ],
            TaskColumns::LIST => [
                new Attribute('client', 'work.tasks.field.client', AttributeType::Text, static fn (MyTaskLine $line): ?string => $line->task->clientName, defaultVisible: true, defaultPosition: 25, phone: false, wide: true, truncate: true),
            ],
            default => [],
        };
    }
}
