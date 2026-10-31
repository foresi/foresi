<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Party\Infrastructure\Doctrine;

use App\Audit\Domain\AuditEntry;
use App\Party\Domain\ContactLink;
use App\Party\Domain\Party;
use App\Party\Domain\PartyAddress;
use App\Party\Domain\PartyBankAccount;
use App\Party\Domain\PartyEmail;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Whether parties changed after a point in time: the parties themselves and
 * everything that belongs to them (addresses, e-mails, bank accounts and
 * contact links are audited as entities of their own). The audit log is read
 * by entity id: the children a party has now and the ones a write noted
 * (which may be gone by now). Reads the audit log through the tenant filter.
 */
final readonly class DoctrinePartyChanges
{
    public function __construct(private EntityManagerInterface $em)
    {
    }

    /**
     * @param list<int>                      $partyIds
     * @param array<class-string, list<int>> $knownChildren        children a write noted, such as the ones it added
     * @param list<string>                   $exceptCorrelationIds
     */
    public function changedSince(array $partyIds, array $knownChildren, \DateTimeImmutable $since, array $exceptCorrelationIds): bool
    {
        $entities = [Party::class => $partyIds];
        foreach ($this->childrenOf($partyIds) as $class => $ids) {
            $entities[$class] = $ids;
        }
        foreach ($knownChildren as $class => $ids) {
            $entities[$class] = [...$entities[$class] ?? [], ...$ids];
        }

        // One query per entity class: each uses the index over tenant, class and id, which an OR of classes would not.
        foreach ($entities as $class => $ids) {
            if ([] === $ids) {
                continue;
            }
            $dql = 'SELECT COUNT(e.id) FROM '.AuditEntry::class.' e WHERE e.entityClass = :class AND e.entityId IN (:ids) AND e.occurredAt >= :since';
            if ([] !== $exceptCorrelationIds) {
                $dql .= ' AND (e.correlationId IS NULL OR e.correlationId NOT IN (:correlations))';
            }
            $query = $this->em->createQuery($dql)
                ->setParameter('class', $class)
                ->setParameter('ids', array_values(array_unique(array_map(strval(...), $ids))))
                ->setParameter('since', $since, 'datetime_immutable');
            if ([] !== $exceptCorrelationIds) {
                $query->setParameter('correlations', $exceptCorrelationIds);
            }
            if ((int) $query->getSingleScalarResult() > 0) {
                return true;
            }
        }

        return false;
    }

    /**
     * The ids of the addresses, e-mails, bank accounts and contact links (on
     * either side) of the parties, in one query. The parties were found through
     * the tenant filter; their children belong to the same tenant.
     *
     * @param list<int> $partyIds
     *
     * @return array<class-string, list<int>>
     */
    public function childrenOf(array $partyIds): array
    {
        if ([] === $partyIds) {
            return [];
        }
        $rows = $this->em->getConnection()->fetchAllNumeric(
            "SELECT 'address', id FROM party_address WHERE party_id IN (:parties)"
            ." UNION ALL SELECT 'email', id FROM party_email WHERE party_id IN (:parties)"
            ." UNION ALL SELECT 'bank_account', id FROM party_bank_account WHERE party_id IN (:parties)"
            ." UNION ALL SELECT 'link', id FROM contact_link WHERE company_id IN (:parties) OR person_id IN (:parties)",
            ['parties' => $partyIds],
            ['parties' => ArrayParameterType::INTEGER],
        );
        $classes = ['address' => PartyAddress::class, 'email' => PartyEmail::class, 'bank_account' => PartyBankAccount::class, 'link' => ContactLink::class];
        $children = [];
        foreach ($rows as [$kind, $id]) {
            if (\is_string($kind) && isset($classes[$kind]) && is_numeric($id)) {
                $children[$classes[$kind]][] = (int) $id;
            }
        }

        return $children;
    }
}
