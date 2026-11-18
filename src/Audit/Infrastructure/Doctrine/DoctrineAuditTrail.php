<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Audit\Infrastructure\Doctrine;

use Doctrine\ORM\EntityManagerInterface;
use Foresi\Audit\Application\AuditTrail;
use Foresi\Audit\Domain\AuditEntry;

/**
 * Reads the audit log through the entity manager. One instance serves one
 * customer, so the log holds no rows of anyone else.
 */
final readonly class DoctrineAuditTrail implements AuditTrail
{
    public function __construct(private EntityManagerInterface $em)
    {
    }

    public function changedSince(string $entityClass, string $entityId, \DateTimeImmutable $since, string ...$exceptCorrelationIds): bool
    {
        $query = $this->em->createQueryBuilder()
            ->select('COUNT(e.id)')
            ->from(AuditEntry::class, 'e')
            ->where('e.entityClass = :class')
            ->andWhere('e.entityId = :id')
            ->andWhere('e.occurredAt >= :since')
            ->setParameter('class', $entityClass)
            ->setParameter('id', $entityId)
            ->setParameter('since', $since, 'datetime_immutable');
        if ([] !== $exceptCorrelationIds) {
            $query->andWhere('(e.correlationId IS NULL OR e.correlationId NOT IN (:correlations))')->setParameter('correlations', array_values($exceptCorrelationIds));
        }

        return (int) $query->getQuery()->getSingleScalarResult() > 0;
    }

    public function rootHistory(string $rootClass, string $rootId): array
    {
        /** @var list<AuditEntry> $entries */
        $entries = $this->em->createQuery('SELECT a FROM '.AuditEntry::class.' a WHERE a.rootClass = :class AND a.rootId = :id ORDER BY a.occurredAt, a.id')
            ->setParameter('class', $rootClass)
            ->setParameter('id', $rootId)
            ->getResult();

        return $entries;
    }
}
