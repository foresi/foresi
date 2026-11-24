<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Purchasing\Infrastructure\Doctrine;

use Doctrine\ORM\EntityManagerInterface;
use Foresi\Purchasing\Application\Reading\DocumentReadings;
use Foresi\Purchasing\Domain\DocumentReading;
use Foresi\Shared\Domain\Id;

final readonly class DoctrineDocumentReadings implements DocumentReadings
{
    public function __construct(private EntityManagerInterface $entityManager)
    {
    }

    public function find(string $documentId): ?DocumentReading
    {
        return Id::isValid($documentId) ? $this->entityManager->find(DocumentReading::class, $documentId) : null;
    }

    public function fresh(string $documentId): ?DocumentReading
    {
        if (!Id::isValid($documentId)) {
            return null;
        }
        $loaded = $this->entityManager->getUnitOfWork()->tryGetById($documentId, DocumentReading::class);
        if ($loaded instanceof DocumentReading) {
            $this->entityManager->detach($loaded);
        }

        return $this->find($documentId);
    }

    public function add(DocumentReading $reading): void
    {
        $this->entityManager->persist($reading);
    }
}
