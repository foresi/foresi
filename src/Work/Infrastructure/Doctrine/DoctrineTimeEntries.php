<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Work\Infrastructure\Doctrine;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\LockMode;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\EntityManagerInterface;
use Foresi\Identity\Domain\Membership;
use Foresi\Shared\Domain\Id;
use Foresi\Work\Application\TimeEntries;
use Foresi\Work\Domain\Task;
use Foresi\Work\Domain\TimeEntry;

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

    public function trashedBefore(\DateTimeImmutable $moment): array
    {
        /** @var list<TimeEntry> $entries */
        $entries = $this->entityManager->createQuery('SELECT e FROM '.TimeEntry::class.' e WHERE e.deletedAt IS NOT NULL AND e.deletedAt < :moment ORDER BY e.deletedAt ASC, e.id ASC')
            ->setParameter('moment', $moment, Types::DATETIME_IMMUTABLE)
            ->getResult();

        return $entries;
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
