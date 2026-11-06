<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Idoklad\Infrastructure\Doctrine;

use App\Idoklad\Application\IdokladConnections;
use App\Idoklad\Domain\IdokladConnection;
use App\Party\Domain\LegalEntity;
use Doctrine\ORM\EntityManagerInterface;

final readonly class DoctrineIdokladConnections implements IdokladConnections
{
    public function __construct(private EntityManagerInterface $em)
    {
    }

    public function for(LegalEntity $firm): ?IdokladConnection
    {
        return $this->em->getRepository(IdokladConnection::class)->findOneBy(['firm' => $firm]);
    }

    public function save(IdokladConnection $connection): void
    {
        $this->em->persist($connection);
    }

    public function remove(IdokladConnection $connection): void
    {
        $this->em->remove($connection);
    }
}
