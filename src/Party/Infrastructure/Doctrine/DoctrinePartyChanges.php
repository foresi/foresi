<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Party\Infrastructure\Doctrine;

use App\Audit\Domain\AuditEntry;
use App\Party\Domain\ContactLink;
use App\Party\Domain\Party;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Whether parties changed after a point in time: the parties themselves and
 * everything that belongs to them. Entries of addresses, e-mails, bank accounts
 * and contact links carry their party as root, so one query by root finds a
 * change to a child that is gone now too. A contact link concerns two parties
 * and is rooted at the company; the person's side is read by the links the
 * person has now, so a link removed since shows in the company's history only.
 * Reads the audit log through the tenant filter.
 */
final readonly class DoctrinePartyChanges
{
    public function __construct(private EntityManagerInterface $em)
    {
    }

    /**
     * @param list<string>                      $partyIds
     * @param array<class-string, list<string>> $legacyChildren       children by entity id, for snapshots written before entries carried a root
     * @param list<string>                      $exceptCorrelationIds
     */
    public function changedSince(array $partyIds, array $legacyChildren, \DateTimeImmutable $since, array $exceptCorrelationIds): bool
    {
        if ([] === $partyIds) {
            return false;
        }

        if ($this->hasEntry('rootClass', 'rootId', Party::class, $partyIds, $since, $exceptCorrelationIds)) {
            return true;
        }
        if ($this->hasEntry('entityClass', 'entityId', ContactLink::class, $this->linksOfPersons($partyIds), $since, $exceptCorrelationIds)) {
            return true;
        }

        // Only serves snapshots written before this release: entries made earlier of a child
        // removed before the root migration are rooted at the child itself, and the ids the
        // snapshot noted are the only link to the party.
        return array_any($legacyChildren, fn (array $ids, string $class): bool => $this->hasEntry('entityClass', 'entityId', $class, $ids, $since, $exceptCorrelationIds));
    }

    /**
     * One query per class: each uses the index over tenant, class and id, which an
     * OR of classes would not. SQLite would read the tenant's whole log for it, once per record.
     *
     * @param 'entityClass'|'rootClass' $classField
     * @param 'entityId'|'rootId'       $idField
     * @param list<string>              $ids
     * @param list<string>              $exceptCorrelationIds
     */
    private function hasEntry(string $classField, string $idField, string $class, array $ids, \DateTimeImmutable $since, array $exceptCorrelationIds): bool
    {
        if ([] === $ids) {
            return false;
        }
        $dql = sprintf('SELECT COUNT(e.id) FROM %s e WHERE e.%s = :class AND e.%s IN (:ids) AND e.occurredAt >= :since', AuditEntry::class, $classField, $idField);
        if ([] !== $exceptCorrelationIds) {
            $dql .= ' AND (e.correlationId IS NULL OR e.correlationId NOT IN (:correlations))';
        }
        $query = $this->em->createQuery($dql)
            ->setParameter('class', $class)
            ->setParameter('ids', array_values(array_unique($ids)))
            ->setParameter('since', $since, 'datetime_immutable');
        if ([] !== $exceptCorrelationIds) {
            $query->setParameter('correlations', $exceptCorrelationIds);
        }

        return (int) $query->getSingleScalarResult() > 0;
    }

    /**
     * The ids of the contact links the parties have now as the person. The
     * parties were found through the tenant filter; their links belong to the
     * same tenant.
     *
     * @param list<string> $partyIds
     *
     * @return list<string>
     */
    public function linksOfPersons(array $partyIds): array
    {
        if ([] === $partyIds) {
            return [];
        }
        /** @var list<string> $ids */
        $ids = $this->em->getConnection()->fetchFirstColumn(
            'SELECT id FROM contact_link WHERE person_id IN (:parties)',
            ['parties' => $partyIds],
            ['parties' => ArrayParameterType::STRING],
        );

        return $ids;
    }
}
