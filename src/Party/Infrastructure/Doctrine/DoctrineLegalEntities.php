<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Party\Infrastructure\Doctrine;

use Doctrine\ORM\EntityManagerInterface;
use Foresi\Party\Application\LegalEntities;
use Foresi\Party\Application\UnknownLegalEntity;
use Foresi\Party\Domain\LegalEntity;

final readonly class DoctrineLegalEntities implements LegalEntities
{
    /** A write that changes nothing: SQLite has no row lock, but a write takes the database's, as PostgreSQL takes the row's. */
    private const string LOCK = 'UPDATE legal_entity SET name = name WHERE id = :id';

    public function __construct(private EntityManagerInterface $em)
    {
    }

    public function all(): array
    {
        /** @var list<LegalEntity> $firms */
        $firms = $this->em->createQuery('SELECT f FROM '.LegalEntity::class.' f ORDER BY f.name, f.id')->getResult();

        return $firms;
    }

    public function active(): array
    {
        /** @var list<LegalEntity> $firms */
        $firms = $this->em->createQuery('SELECT f FROM '.LegalEntity::class.' f WHERE f.archivedAt IS NULL ORDER BY f.name, f.id')->getResult();

        return $firms;
    }

    public function get(string $id): LegalEntity
    {
        $firm = $this->em->createQuery('SELECT f FROM '.LegalEntity::class.' f WHERE f.id = :id')->setParameter('id', $id)->getOneOrNullResult();

        return $firm instanceof LegalEntity ? $firm : throw new UnknownLegalEntity();
    }

    public function lock(string $id): LegalEntity
    {
        $locked = $this->em->getConnection()->executeStatement(self::LOCK, ['id' => $id]);
        if (1 !== $locked) {
            throw new UnknownLegalEntity();
        }

        return $this->get($id);
    }

    public function add(LegalEntity $firm): void
    {
        $this->em->persist($firm);
    }
}
