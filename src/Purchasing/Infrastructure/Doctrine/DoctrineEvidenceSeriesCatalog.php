<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Purchasing\Infrastructure\Doctrine;

use Doctrine\ORM\EntityManagerInterface;
use Foresi\Purchasing\Application\EvidenceSeriesCatalog;
use Foresi\Purchasing\Domain\EvidenceSeries;

final readonly class DoctrineEvidenceSeriesCatalog implements EvidenceSeriesCatalog
{
    public function __construct(private EntityManagerInterface $entityManager)
    {
    }

    public function get(): ?EvidenceSeries
    {
        return $this->entityManager->getRepository(EvidenceSeries::class)->findOneBy([]);
    }

    public function add(EvidenceSeries $series): void
    {
        $this->entityManager->persist($series);
        // Stored at once: the counter of its numbers refers to it in the same transaction.
        $this->entityManager->flush();
    }
}
