<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Work\Infrastructure\Directory;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\ORM\EntityManagerInterface;
use Foresi\Shared\Domain\Id;
use Foresi\Work\Application\WorkVisibility;
use Foresi\Work\Contract\TaskDirectory;
use Foresi\Work\Contract\TaskSummary;
use Foresi\Work\Domain\Task;
use Foresi\Work\Infrastructure\Controller\TaskController;
use Foresi\Work\Infrastructure\Doctrine\ScopedDql;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * The tasks by id in one query, kept to what the person sees by ScopedDql, the
 * same rule as every list of Work. The trash is no filter here: a task in it
 * is still the task an id points to, flagged.
 */
final readonly class WorkTaskDirectory implements TaskDirectory
{
    public function __construct(
        private EntityManagerInterface $em,
        private WorkVisibility $visibility,
        private UrlGeneratorInterface $urls,
    ) {
    }

    public function summaries(array $ids): array
    {
        // An id that is none is no task, and never reaches the database.
        $ids = array_values(array_unique(array_filter($ids, Id::isValid(...))));
        if ([] === $ids) {
            return [];
        }
        $seen = ScopedDql::tasks($this->visibility->scope(), 't');
        /** @var list<Task> $tasks */
        $tasks = $seen->apply($this->em
            ->createQuery('SELECT t, s, r, u FROM '.Task::class.' t JOIN t.status s LEFT JOIN t.responsible r LEFT JOIN r.user u'.$seen->joins.' WHERE t.id IN (:ids)'.$seen->where)
            ->setParameter('ids', $ids, ArrayParameterType::STRING))
            ->getResult();
        $summaries = [];
        foreach ($tasks as $task) {
            $status = $task->status();
            $responsible = $task->responsible();
            $summaries[$task->id()] = new TaskSummary(
                $task->id(),
                $task->title(),
                $status->name(),
                $status->type()->value,
                $responsible?->id(),
                $responsible?->user()->displayName(),
                $task->dueDate(),
                $status->type()->isFinished(),
                $task->isDeleted(),
                $this->urls->generate(TaskController::ROUTE, ['id' => $task->id()]),
            );
        }

        return $summaries;
    }
}
