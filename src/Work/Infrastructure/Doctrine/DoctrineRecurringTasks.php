<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Work\Infrastructure\Doctrine;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\EntityManagerInterface;
use Foresi\Shared\Domain\Id;
use Foresi\Work\Application\RecurringTasks;
use Foresi\Work\Domain\RecurringTask;
use Foresi\Work\Domain\RecurringTaskState;
use Foresi\Work\Domain\Task;

final readonly class DoctrineRecurringTasks implements RecurringTasks
{
    // A write that changes nothing takes the row's lock on PostgreSQL and the database's on SQLite.
    private const string LOCK = 'UPDATE recurring_task SET version = version WHERE id = :id';

    public function __construct(private EntityManagerInterface $em)
    {
    }

    public function add(RecurringTask $recurringTask): void
    {
        $this->em->persist($recurringTask);
    }

    public function find(string $id): ?RecurringTask
    {
        return Id::isValid($id) ? $this->em->find(RecurringTask::class, $id) : null;
    }

    public function lock(string $id): ?RecurringTask
    {
        if (!Id::isValid($id) || 1 !== $this->em->getConnection()->executeStatement(self::LOCK, ['id' => $id])) {
            return null;
        }
        // What this process remembers may be older than the lock, and a task loaded
        // before it would point to an object the session no longer knows, so the
        // session starts over, as a recurring invoice's run does.
        $this->em->clear();

        return $this->em->find(RecurringTask::class, $id);
    }

    public function due(\DateTimeImmutable $until): array
    {
        /** @var list<string> $ids */
        $ids = $this->em->createQuery('SELECT r.id FROM '.RecurringTask::class.' r WHERE r.state = :active AND r.nextOccurrence <= :until ORDER BY r.nextOccurrence, r.id')
            ->setParameter('active', RecurringTaskState::Active->value)
            ->setParameter('until', new \DateTimeImmutable($until->format('Y-m-d')), Types::DATE_IMMUTABLE)
            ->getSingleColumnResult();

        return $ids;
    }

    public function all(): array
    {
        /** @var list<RecurringTask> $all */
        $all = $this->em->createQuery('SELECT r, p FROM '.RecurringTask::class.' r JOIN r.project p')->getResult();
        $order = [RecurringTaskState::Active->value => 0, RecurringTaskState::Paused->value => 1, RecurringTaskState::Ended->value => 2];
        usort($all, static fn (RecurringTask $a, RecurringTask $b): int => [$order[$a->state()->value], $a->nextOccurrence()?->format('Y-m-d') ?? '9999', mb_strtolower($a->title()), $a->id()]
            <=> [$order[$b->state()->value], $b->nextOccurrence()?->format('Y-m-d') ?? '9999', mb_strtolower($b->title()), $b->id()]);

        return $all;
    }

    public function tasksOf(RecurringTask $recurringTask, int $limit): array
    {
        /** @var list<Task> $tasks */
        $tasks = $this->em->createQuery('SELECT t FROM '.Task::class.' t WHERE t.recurringTask = :recurring ORDER BY t.occurrenceOn DESC')
            ->setParameter('recurring', $recurringTask->id())
            ->setMaxResults($limit)
            ->getResult();

        return $tasks;
    }
}
