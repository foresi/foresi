<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Idoklad\Infrastructure\Doctrine;

use App\Idoklad\Application\Import\IdokladImportRuns;
use App\Idoklad\Domain\IdokladImportRun;
use App\Party\Domain\LegalEntity;
use Doctrine\ORM\EntityManagerInterface;

final readonly class DoctrineIdokladImportRuns implements IdokladImportRuns
{
    public function __construct(private EntityManagerInterface $em)
    {
    }

    public function add(IdokladImportRun $run): void
    {
        $this->em->persist($run);
    }

    /** Ids are ordered by time, so they also order runs that started in the same second. */
    public function forFirm(LegalEntity $firm): array
    {
        return $this->em->getRepository(IdokladImportRun::class)->findBy(['firm' => $firm], ['startedAt' => 'DESC', 'id' => 'DESC']);
    }
}
