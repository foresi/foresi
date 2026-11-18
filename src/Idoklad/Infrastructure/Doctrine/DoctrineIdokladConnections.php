<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Idoklad\Infrastructure\Doctrine;

use Doctrine\ORM\EntityManagerInterface;
use Foresi\Idoklad\Application\IdokladConnections;
use Foresi\Idoklad\Domain\IdokladConnection;
use Foresi\Party\Domain\LegalEntity;

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
