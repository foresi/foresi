<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Work\Infrastructure\Doctrine;

use App\Shared\Domain\Id;
use App\Work\Application\Tasks;
use App\Work\Domain\Label;
use App\Work\Domain\Project;
use App\Work\Domain\ProjectStatus;
use App\Work\Domain\Section;
use App\Work\Domain\Task;
use App\Work\Domain\TaskLabel;
use App\Work\Domain\TaskWatcher;
use Doctrine\ORM\EntityManagerInterface;

final readonly class DoctrineTasks implements Tasks
{
    public function __construct(private EntityManagerInterface $entityManager)
    {
    }

    public function task(string $id): ?Task
    {
        return Id::isValid($id) ? $this->entityManager->getRepository(Task::class)->findOneBy(['id' => $id]) : null;
    }

    public function siblings(Project $project, ?Task $parent): array
    {
        return $this->entityManager->getRepository(Task::class)->findBy(['project' => $project, 'parent' => $parent], ['position' => 'ASC', 'createdAt' => 'ASC', 'id' => 'ASC']);
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

    public function remove(TaskWatcher|TaskLabel $item): void
    {
        $this->entityManager->remove($item);
    }
}
