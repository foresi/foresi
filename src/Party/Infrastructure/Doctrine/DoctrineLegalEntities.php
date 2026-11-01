<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Party\Infrastructure\Doctrine;

use App\Party\Application\LegalEntities;
use App\Party\Application\UnknownLegalEntity;
use App\Party\Domain\LegalEntity;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;

final readonly class DoctrineLegalEntities implements LegalEntities
{
    public function __construct(private EntityManagerInterface $em)
    {
    }

    public function all(): array
    {
        /** @var list<LegalEntity> $firms */
        $firms = $this->em->createQuery('SELECT f FROM '.LegalEntity::class.' f ORDER BY f.name, f.id')->getResult();

        return $firms;
    }

    public function get(string $id): LegalEntity
    {
        // A query, not find(): find() answers from the identity map without the tenant filter.
        $firm = $this->em->createQuery('SELECT f FROM '.LegalEntity::class.' f WHERE f.id = :id')->setParameter('id', $id)->getOneOrNullResult();

        return $firm instanceof LegalEntity ? $firm : throw new UnknownLegalEntity();
    }

    public function lockActive(): array
    {
        /** @var list<LegalEntity> $firms */
        $firms = $this->em->createQuery('SELECT f FROM '.LegalEntity::class.' f WHERE f.archivedAt IS NULL ORDER BY f.id')
            ->setLockMode(LockMode::PESSIMISTIC_WRITE)
            ->getResult();

        return $firms;
    }

    public function add(LegalEntity $firm): void
    {
        $this->em->persist($firm);
    }
}
