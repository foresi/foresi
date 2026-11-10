<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Work\Infrastructure\Doctrine;

use App\Identity\Domain\Membership;
use App\Shared\Domain\Id;
use App\Work\Application\TimeEntries;
use App\Work\Domain\Task;
use App\Work\Domain\TimeEntry;
use Doctrine\ORM\EntityManagerInterface;

final readonly class DoctrineTimeEntries implements TimeEntries
{
    public function __construct(private EntityManagerInterface $entityManager)
    {
    }

    public function entry(string $id): ?TimeEntry
    {
        return Id::isValid($id) ? $this->entityManager->getRepository(TimeEntry::class)->findOneBy(['id' => $id]) : null;
    }

    public function running(Membership $membership): ?TimeEntry
    {
        return $this->entityManager->getRepository(TimeEntry::class)->findOneBy(['runningFor' => $membership->id()]);
    }

    public function ofTask(Task $task): array
    {
        return $this->entityManager->getRepository(TimeEntry::class)->findBy(['task' => $task], ['workedOn' => 'ASC', 'createdAt' => 'ASC', 'id' => 'ASC']);
    }

    public function add(TimeEntry $entry): void
    {
        $this->entityManager->persist($entry);
    }

    public function remove(TimeEntry $entry): void
    {
        $this->entityManager->remove($entry);
    }
}
