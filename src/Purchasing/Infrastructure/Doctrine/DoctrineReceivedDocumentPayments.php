<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Purchasing\Infrastructure\Doctrine;

use App\Purchasing\Application\ReceivedDocumentPayments;
use App\Purchasing\Domain\ReceivedDocumentPayment;
use App\Shared\Domain\Id;
use Doctrine\ORM\EntityManagerInterface;

final readonly class DoctrineReceivedDocumentPayments implements ReceivedDocumentPayments
{
    public function __construct(private EntityManagerInterface $entityManager)
    {
    }

    public function add(ReceivedDocumentPayment $payment): void
    {
        $this->entityManager->persist($payment);
    }

    public function remove(ReceivedDocumentPayment $payment): void
    {
        $this->entityManager->remove($payment);
    }

    public function ofDocument(string $documentId): array
    {
        if (!Id::isValid($documentId)) {
            return [];
        }
        /** @var list<ReceivedDocumentPayment> $payments */
        $payments = $this->entityManager->createQuery('SELECT p FROM '.ReceivedDocumentPayment::class.' p WHERE IDENTITY(p.document) = :document ORDER BY p.paidOn ASC, p.createdAt ASC, p.id ASC')
            ->setParameter('document', $documentId)
            ->getResult();

        return $payments;
    }

    public function sums(array $documentIds): array
    {
        $documentIds = array_values(array_filter($documentIds, Id::isValid(...)));
        if ([] === $documentIds) {
            return [];
        }
        /** @var list<array{document: string, paid: int|numeric-string, last: string}> $rows */
        $rows = $this->entityManager->createQuery('SELECT IDENTITY(p.document) AS document, SUM(p.amountMinor) AS paid, MAX(p.paidOn) AS last FROM '.ReceivedDocumentPayment::class.' p WHERE IDENTITY(p.document) IN (:documents) GROUP BY p.document')
            ->setParameter('documents', $documentIds)
            ->getArrayResult();
        $sums = [];
        foreach ($rows as $row) {
            $sums[$row['document']] = ['paid' => (int) $row['paid'], 'last' => new \DateTimeImmutable(substr($row['last'], 0, 10))];
        }

        return $sums;
    }
}
