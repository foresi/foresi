<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Idoklad\Infrastructure\Doctrine;

use App\Idoklad\Application\IdokladJobs;
use App\Idoklad\Domain\IdokladJob;
use App\Idoklad\Domain\IdokladJobState;
use App\Party\Domain\LegalEntity;
use Doctrine\ORM\EntityManagerInterface;

/** Ids are ordered by time, so they also order jobs asked for in the same second. */
final readonly class DoctrineIdokladJobs implements IdokladJobs
{
    public function __construct(private EntityManagerInterface $em)
    {
    }

    public function add(IdokladJob $job): void
    {
        $this->em->persist($job);
    }

    public function find(string $id): ?IdokladJob
    {
        return $this->em->find(IdokladJob::class, $id);
    }

    public function latest(LegalEntity $firm): ?IdokladJob
    {
        return $this->em->getRepository(IdokladJob::class)->findOneBy(['firm' => $firm], ['requestedAt' => 'DESC', 'id' => 'DESC']);
    }

    public function busy(LegalEntity $firm): ?IdokladJob
    {
        return $this->em->getRepository(IdokladJob::class)->findOneBy(['firm' => $firm, 'state' => [IdokladJobState::Pending, IdokladJobState::Running]], ['requestedAt' => 'DESC', 'id' => 'DESC']);
    }
}
