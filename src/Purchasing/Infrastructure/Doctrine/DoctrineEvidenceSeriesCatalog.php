<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Purchasing\Infrastructure\Doctrine;

use App\Party\Domain\LegalEntity;
use App\Purchasing\Application\EvidenceSeriesCatalog;
use App\Purchasing\Domain\EvidenceSeries;
use Doctrine\ORM\EntityManagerInterface;

final readonly class DoctrineEvidenceSeriesCatalog implements EvidenceSeriesCatalog
{
    public function __construct(private EntityManagerInterface $entityManager)
    {
    }

    public function ofFirm(LegalEntity $firm): ?EvidenceSeries
    {
        return $this->entityManager->getRepository(EvidenceSeries::class)->findOneBy(['firm' => $firm]);
    }

    public function add(EvidenceSeries $series): void
    {
        $this->entityManager->persist($series);
    }
}
