<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Timesheet\Infrastructure\Doctrine;

use Doctrine\ORM\EntityManagerInterface;
use Foresi\Shared\Domain\Id;
use Foresi\Timesheet\Domain\TimeEntry;
use Foresi\Work\Contract\TaskTimes;

/**
 * The minutes logged on each task for Work's lists, the time in the trash and
 * a running timer left out.
 */
final readonly class DoctrineTaskTimes implements TaskTimes
{
    public function __construct(private EntityManagerInterface $entityManager)
    {
    }

    public function minutes(array $taskIds): array
    {
        $taskIds = array_values(array_filter($taskIds, Id::isValid(...)));
        if ([] === $taskIds) {
            return [];
        }
        /** @var list<array{task: string, minutes: int|string|null}> $sums */
        $sums = $this->entityManager
            ->createQuery('SELECT IDENTITY(e.task) AS task, SUM(e.minutes) AS minutes FROM '.TimeEntry::class.' e WHERE e.task IN (:tasks) AND e.deletedAt IS NULL GROUP BY e.task')
            ->setParameter('tasks', $taskIds)
            ->getScalarResult();

        return array_map(static fn (int|string|null $minutes): int => (int) $minutes, array_column($sums, 'minutes', 'task'));
    }
}
