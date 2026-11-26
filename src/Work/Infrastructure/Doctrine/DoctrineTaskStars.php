<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Work\Infrastructure\Doctrine;

use Doctrine\ORM\EntityManagerInterface;
use Foresi\Identity\Domain\Membership;
use Foresi\Work\Application\TaskStars;
use Foresi\Work\Domain\Task;
use Foresi\Work\Domain\TaskStar;

final readonly class DoctrineTaskStars implements TaskStars
{
    public function __construct(private EntityManagerInterface $entityManager)
    {
    }

    public function of(Membership $membership): array
    {
        return $this->entityManager->getRepository(TaskStar::class)->findBy(['membership' => $membership], ['position' => 'ASC', 'createdAt' => 'ASC']);
    }

    public function star(Task $task, Membership $membership): ?TaskStar
    {
        return $this->entityManager->getRepository(TaskStar::class)->findOneBy(['task' => $task, 'membership' => $membership]);
    }

    public function onTask(Task $task): array
    {
        return $this->entityManager->getRepository(TaskStar::class)->findBy(['task' => $task]);
    }

    public function add(TaskStar $star): void
    {
        $this->entityManager->persist($star);
    }

    public function remove(TaskStar $star): void
    {
        $this->entityManager->remove($star);
    }
}
