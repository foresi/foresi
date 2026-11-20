<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Idoklad\Infrastructure\Doctrine;

use Doctrine\ORM\EntityManagerInterface;
use Foresi\Idoklad\Application\IdokladJobs;
use Foresi\Idoklad\Domain\IdokladJob;
use Foresi\Idoklad\Domain\IdokladJobState;

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

    public function latest(): ?IdokladJob
    {
        return $this->em->getRepository(IdokladJob::class)->findOneBy([], ['requestedAt' => 'DESC', 'id' => 'DESC']);
    }

    public function busy(): ?IdokladJob
    {
        return $this->em->getRepository(IdokladJob::class)->findOneBy(['state' => [IdokladJobState::Pending, IdokladJobState::Running]], ['requestedAt' => 'DESC', 'id' => 'DESC']);
    }
}
