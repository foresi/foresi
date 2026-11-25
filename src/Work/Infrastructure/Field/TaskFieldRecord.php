<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Work\Infrastructure\Field;

use Foresi\Shared\Application\Field\FieldRecord;
use Foresi\Work\Application\TaskRow;
use Foresi\Work\Application\View\ProjectTaskLists;
use Foresi\Work\Contract\Field\TaskExtension;
use Foresi\Work\Domain\Task;

/**
 * Tasks take custom fields (ADR 0045) through TaskExtension.
 */
final readonly class TaskFieldRecord implements FieldRecord
{
    public function record(): string
    {
        return TaskExtension::RECORD;
    }

    public function entity(): string
    {
        return Task::class;
    }

    public function extension(): string
    {
        return TaskExtension::class;
    }

    public function lists(): array
    {
        return [ProjectTaskLists::KEY => 't'];
    }

    public function sorted(): array
    {
        return [];
    }

    public function id(mixed $row): string
    {
        \assert($row instanceof TaskRow);

        return $row->id;
    }
}
