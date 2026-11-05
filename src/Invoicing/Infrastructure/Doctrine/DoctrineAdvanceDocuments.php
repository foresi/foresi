<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Invoicing\Infrastructure\Doctrine;

use App\Invoicing\Application\AdvanceDocuments;
use App\Invoicing\Domain\DocumentKind;
use App\Invoicing\Domain\Invoice;
use App\Invoicing\Domain\Payment;
use App\Invoicing\Domain\PaymentAllocation;
use App\Tenancy\Application\TenantContext;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\ORM\EntityManagerInterface;

final readonly class DoctrineAdvanceDocuments implements AdvanceDocuments
{
    public function __construct(private EntityManagerInterface $em, private TenantContext $context)
    {
    }

    public function claimDeduction(string $documentId, string $invoiceId): bool
    {
        return 1 === $this->em->getConnection()->executeStatement(
            'UPDATE invoice SET deducted_by_invoice_id = :invoice WHERE id = :id AND tenant_id = :tenant AND (deducted_by_invoice_id IS NULL OR deducted_by_invoice_id = :invoice)',
            ['invoice' => $invoiceId, 'id' => $documentId, 'tenant' => $this->context->current()->toString()],
        );
    }

    public function releaseDeductions(string $invoiceId, array $keep): void
    {
        $this->em->getConnection()->executeStatement(
            'UPDATE invoice SET deducted_by_invoice_id = NULL WHERE tenant_id = :tenant AND deducted_by_invoice_id = :invoice'.([] === $keep ? '' : ' AND id NOT IN (:keep)'),
            ['invoice' => $invoiceId, 'tenant' => $this->context->current()->toString(), 'keep' => $keep],
            ['keep' => ArrayParameterType::STRING],
        );
    }

    public function deductibleFor(string $firmId, string $customerId, ?string $except): array
    {
        $free = '(i.deductedByInvoiceId IS NULL'.(null === $except ? ')' : ' OR i.deductedByInvoiceId = :except)');
        $documents = $this->em->createQuery(
            'SELECT i.id FROM '.Invoice::class.' i, '.Payment::class.' p WHERE p.id = i.paymentId AND i.kind = :tax AND i.firm = :firm AND i.customerParty = :customer'
            .' AND i.deletedAt IS NULL AND p.cancelledAt IS NULL AND '.$free.' ORDER BY i.taxPointDate, i.numberKey',
        )->setParameter('tax', DocumentKind::PaymentTaxDocument->value);
        $advances = $this->em->createQuery(
            'SELECT i.id FROM '.Invoice::class.' i WHERE i.kind = :advance AND i.firm = :firm AND i.customerParty = :customer AND i.deletedAt IS NULL AND '.$free.' ORDER BY i.issueDate, i.numberKey',
        )->setParameter('advance', DocumentKind::Advance->value);
        foreach ([$documents, $advances] as $query) {
            $query->setParameter('firm', $firmId)->setParameter('customer', $customerId);
            if (null !== $except) {
                $query->setParameter('except', $except);
            }
        }
        /** @var list<string> $taxDocuments */
        $taxDocuments = $documents->getSingleColumnResult();
        /** @var list<string> $advanceIds */
        $advanceIds = $advances->getSingleColumnResult();

        return ['taxDocuments' => $taxDocuments, 'advances' => $advanceIds];
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
