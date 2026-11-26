<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Work\Integration\Activities;

use Foresi\Activities\Contract\ActivityTarget;
use Foresi\Shared\Domain\Id;
use Foresi\Work\Application\TaskDetail;
use Foresi\Work\Application\WorkQueries;
use Foresi\Work\Infrastructure\Controller\TaskController;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Activities about a task, seen by whoever sees the task: a task in the trash
 * is gone to them. A task of an archived project takes no new activities, as
 * it takes no changes.
 */
final readonly class TaskActivityTarget implements ActivityTarget
{
    public const string KIND = 'task';

    public function __construct(private WorkQueries $queries, private UrlGeneratorInterface $urls)
    {
    }

    public function kinds(): array
    {
        return [self::KIND];
    }

    public function canSee(string $kind, string $id): bool
    {
        return $this->task($kind, $id) instanceof TaskDetail;
    }

    public function canLink(string $kind, string $id): bool
    {
        $task = $this->task($kind, $id);

        return $task instanceof TaskDetail && !$task->projectArchived;
    }

    public function label(string $kind, string $id): ?string
    {
        return $this->task($kind, $id)?->task->title;
    }

    public function url(string $kind, string $id): ?string
    {
        $task = $this->task($kind, $id);

        return $task instanceof TaskDetail ? $this->urls->generate(TaskController::ROUTE, ['id' => $task->task->id]) : null;
    }

    private function task(string $kind, string $id): ?TaskDetail
    {
        if (self::KIND !== $kind || !Id::isValid($id)) {
            return null;
        }

        return $this->queries->task($id);
    }
}
