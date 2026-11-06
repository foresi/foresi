<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Idoklad\Infrastructure\Doctrine;

use App\Idoklad\Application\Import\IdokladLinks;
use App\Idoklad\Domain\IdokladLink;
use App\Idoklad\Domain\IdokladResource;
use App\Party\Domain\LegalEntity;
use Doctrine\ORM\EntityManagerInterface;

final readonly class DoctrineIdokladLinks implements IdokladLinks
{
    public function __construct(private EntityManagerInterface $em)
    {
    }

    public function find(LegalEntity $firm, IdokladResource $resource, int $externalId): ?IdokladLink
    {
        return $this->em->getRepository(IdokladLink::class)->findOneBy(['firm' => $firm, 'resource' => $resource, 'externalId' => $externalId]);
    }

    public function add(IdokladLink $link): void
    {
        // Flushed at once, so the next record of the run finds it.
        $this->em->persist($link);
        $this->em->flush();
    }
}
