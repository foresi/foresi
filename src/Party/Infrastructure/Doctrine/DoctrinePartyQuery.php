<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Party\Infrastructure\Doctrine;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Query;
use Foresi\Party\Application\ImportedBy;
use Foresi\Party\Application\PartyCounts;
use Foresi\Party\Application\PartyPage;
use Foresi\Party\Application\PartyQuery;
use Foresi\Party\Application\PartyRow;
use Foresi\Party\Domain\AddressType;
use Foresi\Party\Domain\ContactLink;
use Foresi\Party\Domain\EmailPurpose;
use Foresi\Party\Domain\Party;
use Foresi\Party\Domain\PartyAddress;
use Foresi\Party\Domain\PartyEmail;
use Foresi\Party\Domain\PartyKind;
use Foresi\Party\Domain\PartyRole;
use Foresi\Party\Domain\RegistryResult;
use Foresi\Party\Infrastructure\Column\ContactSource;
use Foresi\Shared\Application\View\ViewFilter;
use Foresi\Shared\Domain\Id;

/**
 * Roles live in a JSON column, so the role filter and the counts
 * read the narrow id and roles columns and filter in PHP, which behaves the
 * same on every database.
 */
final readonly class DoctrinePartyQuery implements PartyQuery
{
    /** What the list sorts by when a sort names nothing it can sort by, and what breaks a tie. */
    private const string ORDER_NAME = 'LOWER(p.displayName)';

    private const int MAX_TEXT = 100;

    public function __construct(private EntityManagerInterface $em, private PartyConditions $conditions, private ContactSource $source)
    {
    }

    public function search(string $text, ?PartyRole $role, bool $archived, string $sort, int $page, ?ImportedBy $import = null): PartyPage
    {
        [$where, $parameters] = $this->conditions->build(mb_substr($text, 0, self::MAX_TEXT), $role, $archived, $import);
        $total = (int) PartyConditions::bind($this->em->createQuery('SELECT COUNT(p.id) FROM '.Party::class.' p WHERE '.$where), $parameters)->getSingleScalarResult();

        return $this->paged($where, $parameters, $total, $sort, $page, []);
    }

    public function inView(string $text, ViewFilter $view, array $counted, string $sort, int $page, ?ImportedBy $import = null): PartyPage
    {
        [$where, $parameters] = $this->conditions->narrowed(mb_substr($text, 0, self::MAX_TEXT), $import);
        // One query counts the views the list shows a count for, and the one it lists (spec D9).
        $sums = [];
        $counting = $parameters;
        foreach ([...array_values($counted), $view] as $index => $filter) {
            [$condition, $bound] = $this->conditions->inView($filter, 'c'.$index, $import instanceof ImportedBy);
            $sums[] = 'SUM(CASE WHEN '.$condition.' THEN 1 ELSE 0 END)';
            $counting += $bound;
        }
        /** @var list<numeric-string|int|null> $results */
        $results = array_values((array) PartyConditions::bind($this->em->createQuery('SELECT '.implode(', ', $sums).' FROM '.Party::class.' p WHERE '.$where), $counting)->getSingleResult(Query::HYDRATE_ARRAY));
        $values = array_map(static fn (int|string|null $value): int => (int) $value, $results);
        $total = (int) array_pop($values);
        [$condition, $bound] = $this->conditions->inView($view, 'l', $import instanceof ImportedBy);

        return $this->paged($where.' AND '.$condition, $parameters + $bound, $total, $sort, $page, array_combine(array_map(strval(...), array_keys($counted)), $values));
    }

    public function matchingIds(string $text, ?PartyRole $role, bool $archived, ?ImportedBy $import = null, ?ViewFilter $view = null): array
    {
        [$where, $parameters] = $this->conditions->build(mb_substr($text, 0, self::MAX_TEXT), $role, $archived, $import, $view);
        $query = PartyConditions::bind($this->em->createQuery('SELECT p.id FROM '.Party::class.' p WHERE '.$where.' ORDER BY LOWER(p.displayName), p.id'), $parameters);

        return array_values(array_filter($query->getSingleColumnResult(), \is_string(...)));
    }

    public function counts(): PartyCounts
    {
        $active = 'p.archivedAt IS NULL';
        $row = $this->em->createQuery(
            'SELECT SUM(CASE WHEN '.$active.' THEN 1 ELSE 0 END), '
            .'SUM(CASE WHEN '.$active.' AND '.$this->conditions->role('customer').' THEN 1 ELSE 0 END), '
            .'SUM(CASE WHEN '.$active.' AND '.$this->conditions->role('supplier').' THEN 1 ELSE 0 END), '
            .'SUM(CASE WHEN '.$active.' AND p.unreliablePayerResult = :unreliable THEN 1 ELSE 0 END), '
            .'SUM(CASE WHEN p.archivedAt IS NOT NULL THEN 1 ELSE 0 END) FROM '.Party::class.' p',
        )->setParameter('unreliable', RegistryResult::Unreliable)->getSingleResult(Query::HYDRATE_ARRAY);

        /** @var list<numeric-string|int|null> $values */
        $values = array_values((array) $row);

        return new PartyCounts((int) $values[0], (int) $values[1], (int) $values[2], (int) $values[3], (int) $values[4]);
    }

    /**
     * @param array<string, array{mixed, string|ArrayParameterType|null}> $parameters
     * @param array<string, int>                                          $counts
     */
    private function paged(string $where, array $parameters, int $total, string $sort, int $page, array $counts): PartyPage
    {
        $pages = max(1, (int) ceil($total / self::PER_PAGE));
        $page = max(1, min($page, $pages));
        // By the expression the data source gives the attribute (spec DS10), then by the name.
        $descending = str_starts_with($sort, '-');
        $order = array_values(array_unique([$this->source->sorts()[ltrim($sort, '-')] ?? self::ORDER_NAME, self::ORDER_NAME]));
        $ids = PartyConditions::bind($this->em->createQuery(
            'SELECT p.id FROM '.Party::class.' p WHERE '.$where.' ORDER BY '
            .implode(', ', array_map(static fn (string $column, int $index): string => $column.($descending && 0 === $index ? ' DESC' : ''), $order, array_keys($order))).', p.id',
        ), $parameters)->setFirstResult(($page - 1) * self::PER_PAGE)->setMaxResults(self::PER_PAGE);

        return new PartyPage($this->rows(array_values(array_filter($ids->getSingleColumnResult(), \is_string(...)))), $total, $page, $pages, $counts);
    }

    public function find(string $id): ?Party
    {
        return $this->findMany([$id])[0] ?? null;
    }

    public function findMany(array $ids): array
    {
        $ids = Id::onlyUuids($ids);
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
     * @param list<string> $ids
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
            $partyId = $email->party()->id();
            if (!isset($emails[$partyId]) || (EmailPurpose::General === $email->purpose() && EmailPurpose::General !== $emails[$partyId]['purpose'])) {
                $emails[$partyId] = ['address' => $email->address(), 'purpose' => $email->purpose()];
            }
        }

        $billing = [];
        /** @var list<PartyAddress> $addresses */
        $addresses = $this->em->createQuery('SELECT a FROM '.PartyAddress::class.' a WHERE a.party IN (:ids) AND a.type = :type')
            ->setParameter('ids', $ids)->setParameter('type', AddressType::Billing)->getResult();
        foreach ($addresses as $address) {
            $billing[$address->party()->id()] = $address->address();
        }

        $byId = [];
        foreach ($this->findMany($ids) as $party) {
            $id = $party->id();
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
                $party,
                $billing[$id] ?? null,
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
}
