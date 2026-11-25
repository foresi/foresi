<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Work\Infrastructure\Field;

use Foresi\Shared\Application\Field\FieldRecord;
use Foresi\Work\Application\View\ProjectLists;
use Foresi\Work\Contract\Field\ProjectExtension;
use Foresi\Work\Domain\Project;
use Foresi\Work\Infrastructure\Column\ProjectIndexRow;

/**
 * Projects take custom fields (ADR 0045) through ProjectExtension.
 */
final readonly class ProjectFieldRecord implements FieldRecord
{
    public function record(): string
    {
        return ProjectExtension::RECORD;
    }

    public function entity(): string
    {
        return Project::class;
    }

    public function extension(): string
    {
        return ProjectExtension::class;
    }

    public function lists(): array
    {
        return [ProjectLists::KEY => 'p'];
    }

    public function sorted(): array
    {
        return [];
    }

    public function id(mixed $row): string
    {
        \assert($row instanceof ProjectIndexRow);

        return $row->id;
    }
}
