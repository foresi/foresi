<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Invoicing\Infrastructure\Doctrine;

use App\Invoicing\Application\AdvanceDocuments;
use App\Invoicing\Domain\DocumentKind;
use App\Invoicing\Domain\Invoice;
use App\Invoicing\Domain\PaymentAllocation;
use Doctrine\ORM\EntityManagerInterface;

final readonly class DoctrineAdvanceDocuments implements AdvanceDocuments
{
    public function __construct(private EntityManagerInterface $em)
    {
    }

    public function taxDocumentOf(string $advanceId, string $paymentId): ?string
    {
        $id = $this->em->createQuery('SELECT i.id FROM '.Invoice::class.' i WHERE i.advanceInvoiceId = :advance AND i.paymentId = :payment')
            ->setParameter('advance', $advanceId)
            ->setParameter('payment', $paymentId)
            ->getOneOrNullResult();

        return \is_array($id) && \is_string($id['id'] ?? null) ? $id['id'] : null;
    }

    public function taxDocumentsOfPayment(string $paymentId): array
    {
        /** @var list<string> $ids */
        $ids = $this->em->createQuery('SELECT i.id FROM '.Invoice::class.' i WHERE i.paymentId = :payment ORDER BY i.id')
            ->setParameter('payment', $paymentId)
            ->getSingleColumnResult();

        return $ids;
    }

    public function paidWithoutTaxDocument(): array
    {
        /** @var list<array{advanceId: string, paymentId: string}> $rows */
        $rows = $this->em->createQuery(
            'SELECT IDENTITY(a.invoice) AS advanceId, p.id AS paymentId FROM '.PaymentAllocation::class.' a JOIN a.payment p JOIN a.invoice i'
            .' WHERE i.kind = :advance AND i.deletedAt IS NULL AND p.cancelledAt IS NULL'
            .' AND NOT EXISTS (SELECT t.id FROM '.Invoice::class.' t WHERE t.advanceInvoiceId = i.id AND t.paymentId = p.id)'
            .' ORDER BY p.receivedOn, p.createdAt, p.id, i.id',
        )
            ->setParameter('advance', DocumentKind::Advance->value)
            ->getArrayResult();

        return $rows;
    }
}
