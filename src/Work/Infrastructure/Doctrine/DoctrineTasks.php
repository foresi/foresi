<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Work\Infrastructure\Doctrine;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\EntityManagerInterface;
use Foresi\Shared\Domain\Id;
use Foresi\Work\Application\Tasks;
use Foresi\Work\Domain\Label;
use Foresi\Work\Domain\Project;
use Foresi\Work\Domain\ProjectStatus;
use Foresi\Work\Domain\Section;
use Foresi\Work\Domain\Task;
use Foresi\Work\Domain\TaskLabel;
use Foresi\Work\Domain\TaskWatcher;

final readonly class DoctrineTasks implements Tasks
{
    private const array ORDER = ['position' => 'ASC', 'createdAt' => 'ASC', 'id' => 'ASC'];

    public function __construct(private EntityManagerInterface $entityManager)
    {
    }

    public function task(string $id): ?Task
    {
        return Id::isValid($id) ? $this->entityManager->getRepository(Task::class)->findOneBy(['id' => $id, 'deletedAt' => null]) : null;
    }

    public function trashed(string $id): ?Task
    {
        $task = Id::isValid($id) ? $this->entityManager->getRepository(Task::class)->findOneBy(['id' => $id]) : null;

        return $task instanceof Task && $task->isDeleted() ? $task : null;
    }

    public function siblings(Project $project, ?Task $parent): array
    {
        return $this->entityManager->getRepository(Task::class)->findBy(['project' => $project, 'parent' => $parent, 'deletedAt' => null], self::ORDER);
    }

    public function subtasksOf(Task $parent): array
    {
        return $this->entityManager->getRepository(Task::class)->findBy(['parent' => $parent], self::ORDER);
    }

    public function trashedBefore(\DateTimeImmutable $moment): array
    {
        /* @var list<Task> */
        return $this->entityManager
            ->createQuery('SELECT t FROM '.Task::class.' t WHERE t.deletedAt IS NOT NULL AND t.deletedAt < :moment ORDER BY t.deletedAt ASC, t.id ASC')
            ->setParameter('moment', $moment, Types::DATETIME_IMMUTABLE)
            ->getResult();
    }

    public function nextPosition(Project $project): int
    {
        $max = $this->entityManager->createQuery('SELECT MAX(t.position) FROM '.Task::class.' t WHERE t.project = :project')
            ->setParameter('project', $project->id())
            ->getSingleScalarResult();

        return is_numeric($max) ? (int) $max + 1 : 0;
    }

    public function inStatus(ProjectStatus $status): array
    {
        return $this->entityManager->getRepository(Task::class)->findBy(['status' => $status]);
    }

    public function inSection(Section $section): array
    {
        return $this->entityManager->getRepository(Task::class)->findBy(['section' => $section]);
    }

    public function watchers(Task $task): array
    {
        return $this->entityManager->getRepository(TaskWatcher::class)->findBy(['task' => $task]);
    }

    public function labels(Task $task): array
    {
        return $this->entityManager->getRepository(TaskLabel::class)->findBy(['task' => $task]);
    }

    public function labelled(Label $label): array
    {
        return $this->entityManager->getRepository(TaskLabel::class)->findBy(['label' => $label]);
    }

    public function add(Task|TaskWatcher|TaskLabel $item): void
    {
        $this->entityManager->persist($item);
    }

    public function remove(Task|TaskWatcher|TaskLabel $item): void
    {
        $this->entityManager->remove($item);
    }
}
