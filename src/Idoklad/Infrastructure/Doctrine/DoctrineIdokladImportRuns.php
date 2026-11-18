<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Idoklad\Infrastructure\Doctrine;

use Doctrine\ORM\EntityManagerInterface;
use Foresi\Idoklad\Application\Import\IdokladImportRuns;
use Foresi\Idoklad\Domain\IdokladImportRun;
use Foresi\Party\Domain\LegalEntity;

final readonly class DoctrineIdokladImportRuns implements IdokladImportRuns
{
    public function __construct(private EntityManagerInterface $em)
    {
    }

    public function add(IdokladImportRun $run): void
    {
        $this->em->persist($run);
    }

    public function find(string $id): ?IdokladImportRun
    {
        return $this->em->find(IdokladImportRun::class, $id);
    }

    /** Ids are ordered by time, so they also order runs that started in the same second. */
    public function forFirm(LegalEntity $firm): array
    {
        return $this->em->getRepository(IdokladImportRun::class)->findBy(['firm' => $firm], ['startedAt' => 'DESC', 'id' => 'DESC']);
    }
}
