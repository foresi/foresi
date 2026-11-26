<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Work\Infrastructure\Doctrine;

use Doctrine\Bundle\DoctrineBundle\Attribute\AsDoctrineListener;
use Doctrine\ORM\Event\OnFlushEventArgs;
use Doctrine\ORM\Events;
use Foresi\Work\Domain\Task;
use Foresi\Work\Domain\TaskLabel;
use Psr\Clock\ClockInterface;

/**
 * Keeps when each task last changed (Task::updatedAt), which the list of all tasks
 * filters and sorts by: a flush that changes a task, or adds or removes one of its
 * labels, marks the task changed now. Its comments, watchers and time are not the task.
 */
#[AsDoctrineListener(event: Events::onFlush, priority: 10)]
final readonly class TouchChangedTasks
{
    public function __construct(private ClockInterface $clock)
    {
    }

    public function onFlush(OnFlushEventArgs $args): void
    {
        $uow = $args->getObjectManager()->getUnitOfWork();
        $tasks = [];
        foreach ($uow->getScheduledEntityUpdates() as $entity) {
            if ($entity instanceof Task) {
                $tasks[spl_object_id($entity)] = $entity;
            }
        }
        foreach ([...$uow->getScheduledEntityInsertions(), ...$uow->getScheduledEntityDeletions()] as $entity) {
            if ($entity instanceof TaskLabel && !$uow->isScheduledForInsert($entity->task())) {
                $tasks[spl_object_id($entity->task())] = $entity->task();
            }
        }
        if ([] === $tasks) {
            return;
        }
        $now = \DateTimeImmutable::createFromInterface($this->clock->now());
        $metadata = $args->getObjectManager()->getClassMetadata(Task::class);
        foreach ($tasks as $task) {
            $task->touch($now);
            $uow->recomputeSingleEntityChangeSet($metadata, $task);
        }
    }
}
