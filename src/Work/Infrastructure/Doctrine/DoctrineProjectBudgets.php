<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Work\Infrastructure\Doctrine;

use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Foresi\Shared\Domain\Id;
use Foresi\Work\Application\ProjectBudgetStore;
use Foresi\Work\Domain\Project;
use Foresi\Work\Domain\ProjectBudgetChange;
use Foresi\Work\Domain\Task;
use Foresi\Work\Domain\TimeEntry;

final readonly class DoctrineProjectBudgets implements ProjectBudgetStore
{
    public function __construct(private EntityManagerInterface $entityManager)
    {
    }

    public function change(string $id): ?ProjectBudgetChange
    {
        return Id::isValid($id) ? $this->entityManager->getRepository(ProjectBudgetChange::class)->findOneBy(['id' => $id]) : null;
    }

    public function changes(Project $project): array
    {
        /* @var list<ProjectBudgetChange> */
        return $this->entityManager->getRepository(ProjectBudgetChange::class)->findBy(['project' => $project], ['effectiveOn' => 'DESC', 'createdAt' => 'DESC', 'id' => 'DESC']);
    }

    public function budgetMinutes(Project $project): ?int
    {
        // SUM of no rows is NULL: a project without a change has no budget; one whose changes cancel out has 0.
        $minutes = $this->entityManager->createQueryBuilder()
            ->select('SUM(c.minutes)')
            ->from(ProjectBudgetChange::class, 'c')
            ->where('c.project = :project')
            ->setParameter('project', $project->id())
            ->getQuery()
            ->getSingleScalarResult();

        return null === $minutes ? null : (int) $minutes;
    }

    public function lockedMinutes(Project $project): int
    {
        $this->entityManager->lock($project, LockMode::PESSIMISTIC_WRITE);

        return $this->budgetMinutes($project) ?? 0;
    }

    public function usedMinutes(Project $project, bool $billableOnly): int
    {
        $query = $this->entityManager->createQueryBuilder()
            ->select('COALESCE(SUM(e.minutes), 0)')
            ->from(TimeEntry::class, 'e')
            ->leftJoin('e.task', 't')
            ->where('e.project = :project')
            ->andWhere('e.deletedAt IS NULL')
            ->andWhere('e.minutes IS NOT NULL')
            ->andWhere('t.id IS NULL OR t.deletedAt IS NULL')
            ->setParameter('project', $project->id());
        if ($billableOnly) {
            $query->andWhere('e.billable = true');
        }

        return (int) $query->getQuery()->getSingleScalarResult();
    }

    public function estimateMinutes(Project $project): int
    {
        return (int) $this->entityManager->createQueryBuilder()
            ->select('COALESCE(SUM(t.estimateMinutes), 0)')
            ->from(Task::class, 't')
            ->where('t.project = :project')
            ->andWhere('t.deletedAt IS NULL')
            ->setParameter('project', $project->id())
            ->getQuery()
            ->getSingleScalarResult();
    }

    public function add(ProjectBudgetChange $change): void
    {
        $this->entityManager->persist($change);
    }

    public function remove(ProjectBudgetChange $change): void
    {
        $this->entityManager->remove($change);
    }
}
