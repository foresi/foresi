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

    public function __construct(private EntityManagerInterface $em)
    {
    }

    public function search(string $text, ?PartyRole $role, bool $archived, string $sort, int $page): PartyPage
    {
        $matches = $this->matching($text, $archived, $sort);
        $ids = [];
        foreach ($matches as $match) {
            if (!$role instanceof PartyRole || \in_array($role->value, $match['roles'], true)) {
                $ids[] = $match['id'];
            }
        }

        $total = \count($ids);
        $pages = max(1, (int) ceil($total / self::PER_PAGE));
        $page = max(1, min($page, $pages));

        return new PartyPage($this->rows(\array_slice($ids, ($page - 1) * self::PER_PAGE, self::PER_PAGE)), $total, $page, $pages);
    }

    public function counts(): PartyCounts
    {
        /** @var list<array{roles: list<string>, unreliablePayerResult: RegistryResult|null, archivedAt: \DateTimeImmutable|null}> $rows */
        $rows = $this->em->createQuery('SELECT p.roles, p.unreliablePayerResult, p.archivedAt FROM '.Party::class.' p')->getResult();
        $all = $customers = $suppliers = $unreliable = $archived = 0;
        foreach ($rows as $row) {
            if (null !== $row['archivedAt']) {
                ++$archived;
                continue;
            }
            ++$all;
            $customers += \in_array(PartyRole::Customer->value, $row['roles'], true) ? 1 : 0;
            $suppliers += \in_array(PartyRole::Supplier->value, $row['roles'], true) ? 1 : 0;
            $unreliable += RegistryResult::Unreliable === $row['unreliablePayerResult'] ? 1 : 0;
        }

        return new PartyCounts($all, $customers, $suppliers, $unreliable, $archived);
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
     * @return list<array{id: int, roles: list<string>}>
     */
    private function matching(string $text, bool $archived, string $sort): array
    {
        $dql = 'SELECT p.id, p.roles FROM '.Party::class.' p WHERE '.($archived ? 'p.archivedAt IS NOT NULL' : 'p.archivedAt IS NULL');
        $parameters = [];
        $text = trim($text);
        if ('' !== $text) {
            $conditions = ['LOWER(p.displayName) LIKE :name ESCAPE \'!\'', 'LOWER(p.vatId) LIKE :vat ESCAPE \'!\''];
            $parameters['name'] = '%'.$this->like(mb_strtolower($text)).'%';
            $parameters['vat'] = '%'.$this->like(mb_strtolower((string) preg_replace('/\s+/', '', $text))).'%';
            $key = mb_strtoupper((string) preg_replace('/[^\p{L}\p{N}]/u', '', $text));
            if ('' !== $key) {
                $conditions[] = 'p.registrationKey LIKE :key ESCAPE \'!\'';
                $parameters['key'] = '%'.$this->like($key).'%';
            }
            $conditions[] = 'EXISTS (SELECT 1 FROM '.PartyEmail::class.' e WHERE e.party = p AND LOWER(e.address) LIKE :email ESCAPE \'!\')';
            $parameters['email'] = '%'.$this->like(mb_strtolower($text)).'%';
            $dql .= ' AND ('.implode(' OR ', $conditions).')';
        }

        $descending = str_starts_with($sort, '-');
        $order = self::ORDER[ltrim($sort, '-')] ?? self::ORDER['name'];
        $dql .= ' ORDER BY '.implode(', ', array_map(static fn (string $column, int $index): string => $column.($descending && 0 === $index ? ' DESC' : ''), $order, array_keys($order))).', p.id';

        $query = $this->em->createQuery($dql);
        foreach ($parameters as $name => $value) {
            $query->setParameter($name, $value);
        }

        /** @var list<array{id: int, roles: list<string>}> $rows */
        $rows = $query->getResult(Query::HYDRATE_ARRAY);

        return $rows;
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
