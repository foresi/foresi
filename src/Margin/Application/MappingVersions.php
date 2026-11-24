<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Margin\Application;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\EntityManagerInterface;
use Foresi\Margin\Domain\Allocation;
use Foresi\Margin\Domain\PlannedAllocation;
use Foresi\Margin\Domain\Recipient;
use Foresi\Margin\Domain\SupplierDefault;

/**
 * The versioned mappings in force in a month: the version with the latest
 * month on or before it. No version, or a version that ends the mapping,
 * is no mapping. Reads what is stored, so a writer flushes first.
 */
final readonly class MappingVersions
{
    public function __construct(private EntityManagerInterface $entityManager)
    {
    }

    /**
     * @param \DateTimeImmutable $month any day of the month
     */
    public function planned(string $plannedItemId, \DateTimeImmutable $month): ?Allocation
    {
        $version = $this->latest(PlannedAllocation::class, 'plannedItemId', $plannedItemId, $month);

        return $version instanceof PlannedAllocation ? $version->allocation() : null;
    }

    /**
     * The contacts that bear a share of the planned item's mapping in force in
     * a month, as lowercase contact ids; none without a mapping.
     *
     * @param \DateTimeImmutable $month any day of the month
     *
     * @return list<string>
     */
    public function plannedClients(string $plannedItemId, \DateTimeImmutable $month): array
    {
        $allocation = $this->planned($plannedItemId, $month);
        if (!$allocation instanceof Allocation) {
            return [];
        }
        $clients = [];
        foreach ($allocation->shares as $share) {
            $id = $share->recipient->contactId();
            if (null !== $id) {
                $clients[] = $id;
            }
        }

        return $clients;
    }

    /**
     * @param \DateTimeImmutable $month any day of the month
     */
    public function supplierDefault(string $supplierId, \DateTimeImmutable $month): ?Recipient
    {
        $version = $this->latest(SupplierDefault::class, 'supplierId', $supplierId, $month);

        return $version instanceof SupplierDefault ? $version->recipient() : null;
    }

    /**
     * The first day of the month of a day, as it is written.
     */
    public static function monthOf(\DateTimeImmutable $day): \DateTimeImmutable
    {
        return new \DateTimeImmutable($day->format('Y-m-01'));
    }

    /**
     * @template T of object
     *
     * @param class-string<T> $class
     *
     * @return T|null
     */
    private function latest(string $class, string $field, string $id, \DateTimeImmutable $month): ?object
    {
        /** @var T|null $found */
        $found = $this->entityManager->createQueryBuilder()
            ->select('v')
            ->from($class, 'v')
            ->where('v.'.$field.' = :id')
            ->andWhere('v.fromMonth <= :month')
            ->orderBy('v.fromMonth', 'DESC')
            ->setMaxResults(1)
            ->setParameter('id', $id)
            ->setParameter('month', self::monthOf($month), Types::DATE_IMMUTABLE)
            ->getQuery()
            ->getOneOrNullResult();

        return $found;
    }
}
