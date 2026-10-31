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
     * @param list<string> $partyIds
     * @param list<string> $exceptCorrelationIds
     */
    public function changedSince(array $partyIds, \DateTimeImmutable $since, array $exceptCorrelationIds): bool
    {
        if ([] === $partyIds) {
            return false;
        }

        $conditions = ['(e.rootClass = :rootClass AND e.rootId IN (:roots))'];
        $links = $this->linksOfPersons($partyIds);
        if ([] !== $links) {
            $conditions[] = '(e.entityClass = :linkClass AND e.entityId IN (:links))';
        }
        $dql = 'SELECT COUNT(e.id) FROM '.AuditEntry::class.' e WHERE ('.implode(' OR ', $conditions).') AND e.occurredAt >= :since';
        if ([] !== $exceptCorrelationIds) {
            $dql .= ' AND (e.correlationId IS NULL OR e.correlationId NOT IN (:correlations))';
        }
        $query = $this->em->createQuery($dql)
            ->setParameter('rootClass', Party::class)
            ->setParameter('roots', array_values(array_unique($partyIds)))
            ->setParameter('since', $since, 'datetime_immutable');
        if ([] !== $links) {
            $query->setParameter('linkClass', ContactLink::class)->setParameter('links', $links);
        }
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
