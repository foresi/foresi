<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Party\Infrastructure\Doctrine;

use App\Party\Application\Query\ContactQueries;
use App\Party\Application\Query\ContactRow;
use App\Party\Application\Query\ContactSliceFilter;
use App\Party\Domain\AddressType;
use App\Party\Domain\EmailPurpose;
use App\Party\Domain\Party;
use App\Party\Domain\PartyAddress;
use App\Party\Domain\PartyEmail;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Tenant-scoped through the Doctrine tenant filter, which every query below
 * runs under. The text matches as the search of the screen does.
 */
final readonly class DoctrineContactQueries implements ContactQueries
{
    private const int MAX_TEXT = 100;

    public function __construct(private EntityManagerInterface $em, private PartyConditions $conditions)
    {
    }

    public function after(ContactSliceFilter $filter, ?string $afterId, int $limit): array
    {
        [$where, $parameters] = $this->conditions->build(mb_substr($filter->text, 0, self::MAX_TEXT), $filter->role, $filter->archived);
        if (null !== $filter->registrationNumber) {
            $where .= ' AND p.registrationKey = :registrationKey';
            $parameters['registrationKey'] = mb_strtoupper((string) preg_replace('/[^\p{L}\p{N}]/u', '', $filter->registrationNumber));
        }
        if (null !== $filter->vatId) {
            $where .= ' AND p.vatId = :vatId';
            $parameters['vatId'] = strtoupper((string) preg_replace('/\s+/', '', $filter->vatId));
        }
        if (null !== $afterId) {
            $where .= ' AND p.id < :afterId';
            $parameters['afterId'] = $afterId;
        }
        $query = $this->em->createQuery('SELECT p.id FROM '.Party::class.' p WHERE '.$where.' ORDER BY p.id DESC')->setMaxResults($limit);
        foreach ($parameters as $name => $value) {
            $query->setParameter($name, $value);
        }

        /** @var list<string> $ids */
        $ids = array_values(array_filter($query->getSingleColumnResult(), \is_string(...)));

        return $this->rows($ids);
    }

    /**
     * @param list<string> $ids
     *
     * @return list<ContactRow> in the order of the ids
     */
    private function rows(array $ids): array
    {
        if ([] === $ids) {
            return [];
        }

        $billing = [];
        /** @var list<PartyAddress> $addresses */
        $addresses = $this->em->createQuery('SELECT a FROM '.PartyAddress::class.' a WHERE a.party IN (:ids) AND a.type = :type')
            ->setParameter('ids', $ids)->setParameter('type', AddressType::Billing)->getResult();
        foreach ($addresses as $address) {
            $billing[$address->party()->id()] = $address->address();
        }
        $emails = [];
        /** @var list<PartyEmail> $all */
        $all = $this->em->createQuery('SELECT e FROM '.PartyEmail::class.' e WHERE e.party IN (:ids) AND e.legalEntity IS NULL AND e.purpose = :purpose ORDER BY e.id')
            ->setParameter('ids', $ids)->setParameter('purpose', EmailPurpose::General)->getResult();
        foreach ($all as $email) {
            $emails[$email->party()->id()] ??= $email->address();
        }

        /** @var list<Party> $parties */
        $parties = $this->em->createQuery('SELECT p FROM '.Party::class.' p WHERE p.id IN (:ids)')->setParameter('ids', $ids)->getResult();
        $byId = [];
        foreach ($parties as $party) {
            $byId[$party->id()] = ContactRow::of($party, $billing[$party->id()] ?? null, $emails[$party->id()] ?? null);
        }

        return array_values(array_filter(array_map(static fn (string $id): ?ContactRow => $byId[$id] ?? null, $ids)));
    }
}
