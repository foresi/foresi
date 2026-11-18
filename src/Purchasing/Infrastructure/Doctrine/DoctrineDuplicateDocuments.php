<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Purchasing\Infrastructure\Doctrine;

use Doctrine\ORM\EntityManagerInterface;
use Foresi\Attachment\Domain\Attachment;
use Foresi\Party\Domain\LegalEntity;
use Foresi\Party\Domain\Party;
use Foresi\Purchasing\Application\DuplicateDocuments;
use Foresi\Purchasing\Domain\ReceivedDocument;
use Foresi\Purchasing\Domain\ReceivedDocumentState;

final readonly class DoctrineDuplicateDocuments implements DuplicateDocuments
{
    public function __construct(private EntityManagerInterface $entityManager)
    {
    }

    public function withFile(LegalEntity $firm, string $sha256, string $exceptId): array
    {
        return $this->ids($this->entityManager->createQuery(
            'SELECT d.id FROM '.ReceivedDocument::class.' d JOIN '.Attachment::class.' a WITH a.id = d.originalAttachmentId'
            .' WHERE d.firm = :firm AND d.deletedAt IS NULL AND d.id <> :except AND a.sha256 = :sha256 AND a.deletedAt IS NULL'
            .' ORDER BY d.createdAt, d.id',
        )
            ->setParameter('firm', $firm)
            ->setParameter('except', $exceptId)
            ->setParameter('sha256', $sha256)
            ->getSingleColumnResult());
    }

    public function withSupplierNumber(LegalEntity $firm, Party $supplier, string $supplierNumber, string $exceptId): array
    {
        return $this->ids($this->entityManager->createQuery(
            'SELECT d.id FROM '.ReceivedDocument::class.' d'
            .' WHERE d.firm = :firm AND d.supplierParty = :supplier AND LOWER(d.supplierNumber) = :number'
            .' AND d.state = :recorded AND d.deletedAt IS NULL AND d.id <> :except'
            .' ORDER BY d.createdAt, d.id',
        )
            ->setParameter('firm', $firm)
            ->setParameter('supplier', $supplier)
            ->setParameter('number', mb_strtolower(trim($supplierNumber)))
            ->setParameter('recorded', ReceivedDocumentState::Recorded)
            ->setParameter('except', $exceptId)
            ->getSingleColumnResult());
    }

    /**
     * @param array<mixed> $column
     *
     * @return list<string>
     */
    private function ids(array $column): array
    {
        return array_values(array_map(static fn (mixed $id): string => \is_string($id) ? $id : throw new \LogicException('A document is named by its id.'), $column));
    }
}
