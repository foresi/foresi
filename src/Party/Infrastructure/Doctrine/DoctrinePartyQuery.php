<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Party\Infrastructure\Doctrine;

use App\Party\Application\PartyCounts;
use App\Party\Application\PartyPage;
use App\Party\Application\PartyQuery;
use App\Party\Application\PartyRow;
use App\Party\Domain\ContactLink;
use App\Party\Domain\EmailPurpose;
use App\Party\Domain\Party;
use App\Party\Domain\PartyEmail;
use App\Party\Domain\PartyKind;
use App\Party\Domain\PartyRole;
use App\Party\Domain\RegistryResult;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Query;

/**
 * Tenant-scoped through the Doctrine tenant filter, which every query below
 * runs under. Roles live in a JSON column, so the role filter and the counts
 * read the narrow id and roles columns and filter in PHP, which behaves the
 * same on every database.
 */
final readonly class DoctrinePartyQuery implements PartyQuery
{
    private const array ORDER = [
        'name' => ['LOWER(p.displayName)'],
        'kind' => ['p.kind', 'LOWER(p.displayName)'],
        'registration' => ['p.registrationKey', 'LOWER(p.displayName)'],
        'vat' => ['p.vatId', 'LOWER(p.displayName)'],
    ];

    private const int MAX_TEXT = 100;

    public function __construct(private EntityManagerInterface $em)
    {
    }

    public function search(string $text, ?PartyRole $role, bool $archived, string $sort, int $page): PartyPage
    {
        [$where, $parameters] = $this->conditions(mb_substr($text, 0, self::MAX_TEXT), $role, $archived);

        $count = $this->em->createQuery('SELECT COUNT(p.id) FROM '.Party::class.' p WHERE '.$where);
        foreach ($parameters as $name => $value) {
            $count->setParameter($name, $value);
        }
        $total = (int) $count->getSingleScalarResult();
        $pages = max(1, (int) ceil($total / self::PER_PAGE));
        $page = max(1, min($page, $pages));

        $descending = str_starts_with($sort, '-');
        $order = self::ORDER[ltrim($sort, '-')] ?? self::ORDER['name'];
        $ids = $this->em->createQuery(
            'SELECT p.id FROM '.Party::class.' p WHERE '.$where.' ORDER BY '
            .implode(', ', array_map(static fn (string $column, int $index): string => $column.($descending && 0 === $index ? ' DESC' : ''), $order, array_keys($order))).', p.id',
        )->setFirstResult(($page - 1) * self::PER_PAGE)->setMaxResults(self::PER_PAGE);
        foreach ($parameters as $name => $value) {
            $ids->setParameter($name, $value);
        }

        $pageIds = [];
        foreach ($ids->getSingleColumnResult() as $id) {
            if (\is_int($id)) {
                $pageIds[] = $id;
            }
        }

        return new PartyPage($this->rows($pageIds), $total, $page, $pages);
    }

    public function counts(): PartyCounts
    {
        $active = 'p.archivedAt IS NULL';
        $row = $this->em->createQuery(
            'SELECT SUM(CASE WHEN '.$active.' THEN 1 ELSE 0 END), '
            .'SUM(CASE WHEN '.$active.' AND '.$this->roleCondition('customer').' THEN 1 ELSE 0 END), '
            .'SUM(CASE WHEN '.$active.' AND '.$this->roleCondition('supplier').' THEN 1 ELSE 0 END), '
            .'SUM(CASE WHEN '.$active.' AND p.unreliablePayerResult = :unreliable THEN 1 ELSE 0 END), '
            .'SUM(CASE WHEN p.archivedAt IS NOT NULL THEN 1 ELSE 0 END) FROM '.Party::class.' p',
        )->setParameter('unreliable', RegistryResult::Unreliable)->getSingleResult(Query::HYDRATE_ARRAY);

        /** @var list<numeric-string|int|null> $values */
        $values = array_values((array) $row);

        return new PartyCounts((int) $values[0], (int) $values[1], (int) $values[2], (int) $values[3], (int) $values[4]);
    }

    public function find(int $id): ?Party
    {
        return $this->findMany([$id])[0] ?? null;
    }

    public function findMany(array $ids): array
    {
        if ([] === $ids) {
            return [];
        }

        /** @var list<Party> $parties */
        $parties = $this->em->createQuery('SELECT p FROM '.Party::class.' p WHERE p.id IN (:ids) ORDER BY p.id')->setParameter('ids', array_values(array_unique($ids)))->getResult();

        return $parties;
    }

    public function contactPersonCandidates(Party $company): array
    {
        /** @var list<Party> $persons */
        $persons = $this->em->createQuery(
            'SELECT p FROM '.Party::class.' p WHERE p.kind = :kind AND p.archivedAt IS NULL AND p.id NOT IN ('
            .'SELECT IDENTITY(l.person) FROM '.ContactLink::class.' l WHERE l.company = :company) ORDER BY p.displayName',
        )->setParameter('kind', PartyKind::Person)->setParameter('company', $company)->getResult();

        return $persons;
    }

    /**
     * @return array{string, array<string, string>}
     */
    private function conditions(string $text, ?PartyRole $role, bool $archived): array
    {
        $where = $archived ? 'p.archivedAt IS NOT NULL' : 'p.archivedAt IS NULL';
        $parameters = [];
        if ($role instanceof PartyRole) {
            $where .= ' AND '.$this->roleCondition($role->value);
        }
        $text = trim($text);
        if ('' !== $text) {
            $conditions = ['LOWER(p.displayName) LIKE :name ESCAPE \'!\'', 'LOWER(p.vatId) LIKE :vat ESCAPE \'!\''];
            $parameters['name'] = '%'.self::like(mb_strtolower($text)).'%';
            $parameters['vat'] = '%'.self::like(mb_strtolower((string) preg_replace('/\s+/', '', $text))).'%';
            $key = mb_strtoupper((string) preg_replace('/[^\p{L}\p{N}]/u', '', $text));
            if ('' !== $key) {
                $conditions[] = 'p.registrationKey LIKE :key ESCAPE \'!\'';
                $parameters['key'] = '%'.self::like($key).'%';
            }
            $conditions[] = 'EXISTS (SELECT 1 FROM '.PartyEmail::class.' e WHERE e.party = p AND LOWER(e.address) LIKE :email ESCAPE \'!\')';
            $parameters['email'] = '%'.self::like(mb_strtolower($text)).'%';
            $where .= ' AND ('.implode(' OR ', $conditions).')';
        }

        return [$where, $parameters];
    }

    private function roleCondition(string $role): string
    {
        return 'CAST_TEXT(p.roles) LIKE \'%"'.$role.'"%\'';
    }

    /**
     * @param list<int> $ids
     *
     * @return list<PartyRow>
     */
    private function rows(array $ids): array
    {
        if ([] === $ids) {
            return [];
        }

        $emails = [];
        /** @var list<PartyEmail> $all */
        $all = $this->em->createQuery('SELECT e FROM '.PartyEmail::class.' e WHERE e.party IN (:ids) ORDER BY e.id')->setParameter('ids', $ids)->getResult();
        foreach ($all as $email) {
            $partyId = $email->party()->id() ?? 0;
            if (!isset($emails[$partyId]) || (EmailPurpose::General === $email->purpose() && EmailPurpose::General !== $emails[$partyId]['purpose'])) {
                $emails[$partyId] = ['address' => $email->address(), 'purpose' => $email->purpose()];
            }
        }

        $byId = [];
        foreach ($this->findMany($ids) as $party) {
            $id = $party->id() ?? 0;
            $byId[$id] = new PartyRow(
                $id,
                $party->displayName(),
                $party->kind(),
                $party->roles(),
                $party->registrationNumber()?->value,
                $party->vatId()?->toString(),
                $emails[$id]['address'] ?? null,
                $party->phone(),
                $party->isArchived(),
                RegistryResult::Unreliable === $party->unreliablePayerCheck()?->result,
            );
        }

        $rows = [];
        foreach ($ids as $id) {
            if (isset($byId[$id])) {
                $rows[] = $byId[$id];
            }
        }

        return $rows;
    }

    private function like(string $value): string
    {
        return str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $value);
    }
}
