<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Idoklad\Infrastructure\Doctrine;

use Doctrine\ORM\EntityManagerInterface;
use Foresi\Idoklad\Application\Import\IdokladLinks;
use Foresi\Idoklad\Domain\IdokladLink;
use Foresi\Idoklad\Domain\IdokladResource;
use Foresi\Party\Domain\LegalEntity;

final readonly class DoctrineIdokladLinks implements IdokladLinks
{
    public function __construct(private EntityManagerInterface $em)
    {
    }

    public function find(LegalEntity $firm, IdokladResource $resource, int $externalId): ?IdokladLink
    {
        return $this->em->getRepository(IdokladLink::class)->findOneBy(['firm' => $firm, 'resource' => $resource, 'externalId' => $externalId]);
    }

    public function ofRun(LegalEntity $firm, string $runId): array
    {
        return $this->em->getRepository(IdokladLink::class)->findBy(['firm' => $firm, 'runId' => $runId], ['resource' => 'ASC', 'externalId' => 'ASC']);
    }

    public function remove(IdokladLink $link): void
    {
        $this->em->remove($link);
    }

    public function add(IdokladLink $link): void
    {
        // Flushed at once, so the next record of the run finds it.
        $this->em->persist($link);
        $this->em->flush();
    }
}
