<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Purchasing\Infrastructure\Doctrine;

use App\Purchasing\Application\ReceivedDocuments;
use App\Purchasing\Domain\ReceivedDocument;
use App\Shared\Domain\Id;
use Doctrine\ORM\EntityManagerInterface;

final readonly class DoctrineReceivedDocuments implements ReceivedDocuments
{
    public function __construct(private EntityManagerInterface $entityManager)
    {
    }

    public function find(string $id): ?ReceivedDocument
    {
        return Id::isValid($id) ? $this->entityManager->find(ReceivedDocument::class, $id) : null;
    }

    public function add(ReceivedDocument $document): void
    {
        $this->entityManager->persist($document);
    }
}
