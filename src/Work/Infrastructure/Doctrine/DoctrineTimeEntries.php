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
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\LockMode;
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

    public function lockForInvoicing(array $ids): array
    {
        $ids = array_values(array_filter($ids, Id::isValid(...)));
        if ([] === $ids) {
            return [];
        }
        // In the order of their ids, as every lock of them is taken, so two never wait on each other.
        /** @var list<TimeEntry> $entries */
        $entries = $this->entityManager->createQuery('SELECT e FROM '.TimeEntry::class.' e WHERE e.id IN (:ids) ORDER BY e.id')
            ->setParameter('ids', $ids, ArrayParameterType::STRING)
            ->setLockMode(LockMode::PESSIMISTIC_WRITE)
            ->getResult();
        $byId = [];
        foreach ($entries as $entry) {
            $byId[$entry->id()] = $entry;
        }

        return $byId;
    }

    public function idsInvoicedBy(string $invoiceId): array
    {
        if (!Id::isValid($invoiceId)) {
            return [];
        }
        /** @var list<string> $ids */
        $ids = $this->entityManager->createQuery('SELECT e.id FROM '.TimeEntry::class.' e WHERE e.invoiceId = :invoice')
            ->setParameter('invoice', $invoiceId)
            ->getSingleColumnResult();

        return $ids;
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
