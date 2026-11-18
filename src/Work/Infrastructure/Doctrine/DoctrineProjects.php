<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Work\Infrastructure\Doctrine;

use Doctrine\ORM\EntityManagerInterface;
use Foresi\Shared\Domain\Id;
use Foresi\Work\Application\Projects;
use Foresi\Work\Domain\Project;
use Foresi\Work\Domain\ProjectStatus;
use Foresi\Work\Domain\Section;

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
