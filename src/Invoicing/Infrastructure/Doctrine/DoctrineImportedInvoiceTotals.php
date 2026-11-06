<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Invoicing\Infrastructure\Doctrine;

use App\Invoicing\Application\ImportedInvoiceTotals;
use App\Invoicing\Application\StoredInvoiceTotal;
use App\Invoicing\Domain\Invoice;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Queries of the ORM, so the tenant filter holds.
 */
final readonly class DoctrineImportedInvoiceTotals implements ImportedInvoiceTotals
{
    public function __construct(private EntityManagerInterface $em)
    {
    }

    public function ofRun(string $runId): array
    {
        /** @var list<Invoice> $invoices */
        $invoices = $this->em->createQuery('SELECT i FROM '.Invoice::class.' i WHERE i.importRunId = :run ORDER BY i.id')
            ->setParameter('run', $runId)
            ->getResult();

        return array_map($this->total(...), $invoices);
    }

    public function of(array $invoiceIds): array
    {
        if ([] === $invoiceIds) {
            return [];
        }
        /** @var list<Invoice> $invoices */
        $invoices = $this->em->createQuery('SELECT i FROM '.Invoice::class.' i WHERE i.id IN (:ids) ORDER BY i.id')
            ->setParameter('ids', $invoiceIds)
            ->getResult();

        return array_map($this->total(...), $invoices);
    }

    private function total(Invoice $invoice): StoredInvoiceTotal
    {
        return new StoredInvoiceTotal($invoice->id(), $invoice->issueDate(), $invoice->payable());
    }
}
