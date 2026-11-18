<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Invoicing\Infrastructure\Doctrine;

use Doctrine\ORM\EntityManagerInterface;
use Foresi\Invoicing\Application\ImportedInvoiceTotals;
use Foresi\Invoicing\Application\StoredInvoiceTotal;
use Foresi\Invoicing\Domain\Invoice;

/**
 * Queries of the ORM.
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
