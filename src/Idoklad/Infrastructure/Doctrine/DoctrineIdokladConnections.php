<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Idoklad\Infrastructure\Doctrine;

use Doctrine\ORM\EntityManagerInterface;
use Foresi\Idoklad\Application\IdokladConnections;
use Foresi\Idoklad\Domain\IdokladConnection;

final readonly class DoctrineIdokladConnections implements IdokladConnections
{
    public function __construct(private EntityManagerInterface $em)
    {
    }

    public function get(): ?IdokladConnection
    {
        return $this->em->getRepository(IdokladConnection::class)->findOneBy([]);
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
