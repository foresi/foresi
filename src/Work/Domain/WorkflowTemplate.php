<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Work\Domain;

/**
 * A set of statuses. A new project starts with the simple one; a template
 * applied later replaces the statuses of the project. Once seeded, the
 * statuses belong to the project and are edited there; the project only
 * remembers the template they came from.
 */
enum WorkflowTemplate: string
{
    case Simple = 'simple';
    case Development = 'development';

    /**
     * The statuses to seed, in order. Names are translation keys: the
     * application translates them into the language of the person creating the
     * project, and from then on they are plain editable text.
     *
     * @return list<array{string, StatusType}>
     */
    public function statuses(): array
    {
        return match ($this) {
            self::Simple => [
                ['work.status.to_do', StatusType::Open],
                ['work.status.in_progress', StatusType::Active],
                ['work.status.done', StatusType::Done],
            ],
            self::Development => [
                ['work.status.open', StatusType::Open],
                ['work.status.in_progress', StatusType::Active],
                ['work.status.review', StatusType::Active],
                ['work.status.testing', StatusType::Active],
                ['work.status.blocked', StatusType::Active],
                ['work.status.done', StatusType::Done],
                ['work.status.closed', StatusType::Closed],
            ],
        };
    }
}
