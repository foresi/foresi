<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\TaxesAndContributions\Infrastructure\Doctrine;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\EntityManagerInterface;
use Foresi\Shared\Domain\Id;
use Foresi\TaxesAndContributions\Application\TaxesStore;
use Foresi\TaxesAndContributions\Contract\StatusKind;
use Foresi\TaxesAndContributions\Domain\OwnerProfile;
use Foresi\TaxesAndContributions\Domain\StatusPeriod;

/**
 * The records of the taxes and contributions in Doctrine. A write is flushed
 * by the unit of work around it.
 */
final readonly class DoctrineTaxesStore implements TaxesStore
{
    public function __construct(private EntityManagerInterface $entityManager)
    {
    }

    public function profile(): ?OwnerProfile
    {
        return $this->entityManager->find(OwnerProfile::class, OwnerProfile::ID);
    }

    public function statuses(): array
    {
        $statuses = $this->entityManager->getRepository(StatusPeriod::class)->findBy([], ['validFrom' => 'ASC', 'id' => 'ASC']);
        $order = array_flip(array_map(static fn (StatusKind $kind): string => $kind->value, StatusKind::cases()));
        usort($statuses, static fn (StatusPeriod $a, StatusPeriod $b): int => [$order[$a->kind()->value], $a->validFrom()] <=> [$order[$b->kind()->value], $b->validFrom()]);

        return $statuses;
    }

    public function status(string $id): ?StatusPeriod
    {
        return Id::isValid($id) ? $this->entityManager->find(StatusPeriod::class, $id) : null;
    }

    public function statusStarting(StatusKind $kind, \DateTimeImmutable $day): ?StatusPeriod
    {
        $found = $this->entityManager->createQueryBuilder()
            ->select('status')
            ->from(StatusPeriod::class, 'status')
            ->where('status.kind = :kind')
            ->andWhere('status.validFrom = :day')
            ->setParameter('kind', $kind->value)
            ->setParameter('day', $day, Types::DATE_IMMUTABLE)
            ->getQuery()
            ->getOneOrNullResult();

        return $found instanceof StatusPeriod ? $found : null;
    }

    public function add(OwnerProfile|StatusPeriod $record): void
    {
        $this->entityManager->persist($record);
    }

    public function remove(StatusPeriod $status): void
    {
        $this->entityManager->remove($status);
    }
}
