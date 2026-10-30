<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Audit\Infrastructure\Doctrine;

use App\Audit\Application\AuditTrail;
use App\Audit\Domain\AuditEntry;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Reads the audit log through the entity manager, so the tenant filter
 * confines the answer to the active tenant.
 */
final readonly class DoctrineAuditTrail implements AuditTrail
{
    public function __construct(private EntityManagerInterface $em)
    {
    }

    public function changedSince(string $entityClass, string $entityId, \DateTimeImmutable $since, string $exceptCorrelationId): bool
    {
        $count = $this->em->createQueryBuilder()
            ->select('COUNT(e.id)')
            ->from(AuditEntry::class, 'e')
            ->where('e.entityClass = :class')
            ->andWhere('e.entityId = :id')
            ->andWhere('e.occurredAt >= :since')
            ->andWhere('(e.correlationId IS NULL OR e.correlationId <> :correlation)')
            ->setParameter('class', $entityClass)
            ->setParameter('id', $entityId)
            ->setParameter('since', $since, 'datetime_immutable')
            ->setParameter('correlation', $exceptCorrelationId)
            ->getQuery()
            ->getSingleScalarResult();

        return (int) $count > 0;
    }
}
