<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Work\Infrastructure\Doctrine;

use App\Shared\Domain\Id;
use App\Work\Application\Projects;
use App\Work\Domain\Project;
use App\Work\Domain\ProjectStatus;
use App\Work\Domain\Section;
use Doctrine\ORM\EntityManagerInterface;

final readonly class DoctrineProjects implements Projects
{
    public function __construct(private EntityManagerInterface $entityManager)
    {
    }

    public function project(string $id): ?Project
    {
        return Id::isValid($id) ? $this->entityManager->getRepository(Project::class)->findOneBy(['id' => $id]) : null;
    }

    public function status(string $id): ?ProjectStatus
    {
        return Id::isValid($id) ? $this->entityManager->getRepository(ProjectStatus::class)->findOneBy(['id' => $id]) : null;
    }

    public function section(string $id): ?Section
    {
        return Id::isValid($id) ? $this->entityManager->getRepository(Section::class)->findOneBy(['id' => $id]) : null;
    }

    public function statuses(Project $project): array
    {
        return $this->entityManager->getRepository(ProjectStatus::class)->findBy(['project' => $project], ['position' => 'ASC', 'id' => 'ASC']);
    }

    public function sections(Project $project): array
    {
        return $this->entityManager->getRepository(Section::class)->findBy(['project' => $project], ['position' => 'ASC', 'id' => 'ASC']);
    }

    public function add(Project|ProjectStatus|Section $item): void
    {
        $this->entityManager->persist($item);
    }

    public function remove(ProjectStatus|Section $item): void
    {
        $this->entityManager->remove($item);
    }

    public function flush(): void
    {
        $this->entityManager->flush();
    }

    public function transactional(callable $work): mixed
    {
        $connection = $this->entityManager->getConnection();
        $connection->beginTransaction();
        try {
            $result = $work();
            $this->entityManager->flush();
            $connection->commit();

            return $result;
        } catch (\Throwable $failure) {
            $connection->rollBack();

            throw $failure;
        }
    }
}
