<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Party\Infrastructure\Doctrine;

use App\Audit\Domain\AuditAction;
use App\Audit\Domain\AuditEntry;
use App\Party\Domain\ContactLink;
use App\Party\Domain\Party;
use App\Party\Domain\PartyAddress;
use App\Party\Domain\PartyBankAccount;
use App\Party\Domain\PartyEmail;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Query;

/**
 * Whether parties changed after a point in time: the parties themselves and
 * everything that belongs to them (addresses, e-mails, bank accounts and
 * contact links are audited as entities of their own). Reads the audit log
 * through the tenant filter.
 */
final readonly class DoctrinePartyChanges
{
    /** The children and the fields that point at their party. */
    private const array CHILDREN = [
        PartyAddress::class => ['party'],
        PartyEmail::class => ['party'],
        PartyBankAccount::class => ['party'],
        ContactLink::class => ['company', 'person'],
    ];

    public function __construct(private EntityManagerInterface $em)
    {
    }

    /**
     * @param list<int>                      $partyIds
     * @param array<class-string, list<int>> $knownChildren children that may be gone by now, such as the ones an import added
     */
    public function changedSince(array $partyIds, array $knownChildren, \DateTimeImmutable $since, string $exceptCorrelationId): bool
    {
        $children = $this->children($partyIds, $knownChildren);
        $conditions = ['(e.entityClass = :partyClass AND e.entityId IN (:partyIds))'];
        $parameters = ['partyClass' => Party::class, 'partyIds' => array_map(strval(...), $partyIds)];
        $index = 0;
        foreach ($children as $class => $ids) {
            $conditions[] = \sprintf('(e.entityClass = :class%1$d AND e.entityId IN (:ids%1$d))', $index);
            $parameters['class'.$index] = $class;
            $parameters['ids'.$index] = array_map(strval(...), $ids);
            ++$index;
        }
        if ($this->count(implode(' OR ', $conditions), $parameters, $since, $exceptCorrelationId) > 0) {
            return true;
        }

        return $this->removedChildSince($partyIds, $children, $since, $exceptCorrelationId);
    }

    /**
     * The ids of the current children of the parties, and the known ones.
     *
     * @param list<int>                      $partyIds
     * @param array<class-string, list<int>> $known
     *
     * @return array<class-string, non-empty-list<int>>
     */
    private function children(array $partyIds, array $known): array
    {
        $children = [];
        foreach (array_keys(self::CHILDREN) as $class) {
            $dql = match ($class) {
                PartyAddress::class => 'SELECT c.id FROM '.PartyAddress::class.' c WHERE IDENTITY(c.party) IN (:parties)',
                PartyEmail::class => 'SELECT c.id FROM '.PartyEmail::class.' c WHERE IDENTITY(c.party) IN (:parties)',
                PartyBankAccount::class => 'SELECT c.id FROM '.PartyBankAccount::class.' c WHERE IDENTITY(c.party) IN (:parties)',
                ContactLink::class => 'SELECT c.id FROM '.ContactLink::class.' c WHERE IDENTITY(c.company) IN (:parties) OR IDENTITY(c.person) IN (:parties)',
            };
            /** @var list<int|string> $ids */
            $ids = $this->em->createQuery($dql)->setParameter('parties', $partyIds)->getSingleColumnResult();
            $all = array_values(array_unique([...array_map(intval(...), $ids), ...$known[$class] ?? []]));
            if ([] !== $all) {
                $children[$class] = $all;
            }
        }

        return $children;
    }

    /**
     * A child that was removed by someone else and was not known: its removal
     * entry names its party among the removed values.
     *
     * @param list<int>                      $partyIds
     * @param array<class-string, list<int>> $children
     */
    private function removedChildSince(array $partyIds, array $children, \DateTimeImmutable $since, string $exceptCorrelationId): bool
    {
        /** @var list<AuditEntry> $removals */
        $removals = $this->query('e.entityClass IN (:classes) AND e.action = :deleted', ['classes' => array_keys(self::CHILDREN), 'deleted' => AuditAction::Deleted], $since, $exceptCorrelationId, 'e')->getResult();
        foreach ($removals as $removal) {
            if (\in_array((int) $removal->entityId(), $children[$removal->entityClass()] ?? [], true)) {
                continue;
            }
            foreach (self::CHILDREN[$removal->entityClass()] ?? [] as $field) {
                $pair = $removal->changes()[$field] ?? [null, null];
                if (\in_array($pair[0] ?? null, $partyIds, true)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * @param array<string, mixed> $parameters
     */
    private function count(string $where, array $parameters, \DateTimeImmutable $since, string $exceptCorrelationId): int
    {
        return (int) $this->query($where, $parameters, $since, $exceptCorrelationId, 'COUNT(e.id)')->getSingleScalarResult();
    }

    /**
     * @param array<string, mixed> $parameters
     *
     * @return Query<mixed, mixed>
     */
    private function query(string $where, array $parameters, \DateTimeImmutable $since, string $exceptCorrelationId, string $select): Query
    {
        $query = $this->em->createQuery(
            'SELECT '.$select.' FROM '.AuditEntry::class.' e WHERE ('.$where.')'
            .' AND e.occurredAt >= :since AND (e.correlationId IS NULL OR e.correlationId <> :correlation)',
        )
            ->setParameter('since', $since, 'datetime_immutable')
            ->setParameter('correlation', $exceptCorrelationId);
        foreach ($parameters as $name => $value) {
            $query->setParameter($name, $value);
        }

        return $query;
    }
}
