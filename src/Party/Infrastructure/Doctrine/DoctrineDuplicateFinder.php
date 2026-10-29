<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Party\Infrastructure\Doctrine;

use App\Party\Application\DuplicateFinder;
use App\Party\Application\DuplicateMatch;
use App\Party\Domain\Party;
use App\Party\Domain\RegistrationNumber;
use App\Party\Domain\VatId;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Tenant-scoped through the Doctrine tenant filter. Registration numbers are
 * compared normalised in PHP over the scalar columns of the candidates (same
 * country or same VAT id), so no database specific string functions are needed.
 */
final readonly class DoctrineDuplicateFinder implements DuplicateFinder
{
    public function __construct(private EntityManagerInterface $em)
    {
    }

    public function find(?RegistrationNumber $registrationNumber, ?VatId $vatId, bool $includeArchived = true, ?int $excludePartyId = null): array
    {
        $conditions = [];
        if ($registrationNumber instanceof RegistrationNumber) {
            $conditions[] = 'p.registrationCountry = :country';
        }
        if ($vatId instanceof VatId) {
            $conditions[] = 'p.vatId = :vat';
        }
        if ([] === $conditions) {
            return [];
        }

        $query = $this->em->createQuery(
            'SELECT p.id, p.displayName, p.registrationNumber, p.vatId, p.archivedAt FROM '.Party::class.' p WHERE ('.implode(' OR ', $conditions).')'
            .($includeArchived ? '' : ' AND p.archivedAt IS NULL')
            .(null === $excludePartyId ? '' : ' AND p.id <> :exclude')
            .' ORDER BY p.id',
        );
        if ($registrationNumber instanceof RegistrationNumber) {
            $query->setParameter('country', $registrationNumber->countryCode);
        }
        if ($vatId instanceof VatId) {
            $query->setParameter('vat', $vatId, 'vat_id');
        }
        if (null !== $excludePartyId) {
            $query->setParameter('exclude', $excludePartyId);
        }

        $matches = [];
        /** @var list<array{id: int, displayName: string, registrationNumber: string|null, vatId: VatId|null, archivedAt: \DateTimeImmutable|null}> $rows */
        $rows = $query->getResult();
        foreach ($rows as $row) {
            $sameNumber = $registrationNumber instanceof RegistrationNumber
                && null !== $row['registrationNumber']
                && $registrationNumber->normalised() === new RegistrationNumber($registrationNumber->countryCode, $row['registrationNumber'])->normalised();
            $sameVat = $vatId instanceof VatId && $row['vatId'] instanceof VatId && $vatId->toString() === $row['vatId']->toString();
            if ($sameNumber || $sameVat) {
                $matches[] = new DuplicateMatch($row['id'], $row['displayName'], null !== $row['archivedAt'], $sameNumber, $sameVat);
            }
        }

        return $matches;
    }
}
