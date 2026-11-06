<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Invoicing\Infrastructure\Doctrine;

use App\Invoicing\Application\ImportedDocuments;
use App\Invoicing\Application\UndoBlocker;
use App\Invoicing\Domain\Invoice;
use App\Invoicing\Domain\InvoiceLine;
use App\Invoicing\Domain\InvoiceSend;
use App\Invoicing\Domain\PaymentAllocation;
use App\Party\Domain\LegalEntity;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Queries of the ORM, so the tenant filter holds. Every table that names an invoice
 * is asked about it: its foreign keys would refuse the delete anyway, and here they
 * become reasons a person can read.
 */
final readonly class DoctrineImportedDocuments implements ImportedDocuments
{
    public function __construct(private EntityManagerInterface $em)
    {
    }

    public function find(LegalEntity $firm, string $source, string $externalId): ?Invoice
    {
        /** @var Invoice|null $invoice */
        $invoice = $this->em->createQuery('SELECT i FROM '.Invoice::class.' i WHERE i.firm = :firm AND i.importSource = :source AND i.importExternalId = :external')
            ->setParameter('firm', $firm->id())
            ->setParameter('source', $source)
            ->setParameter('external', $externalId)
            ->getOneOrNullResult();

        return $invoice;
    }

    public function ofRun(string $runId): array
    {
        /** @var list<Invoice> $invoices */
        $invoices = $this->em->createQuery('SELECT i FROM '.Invoice::class.' i WHERE i.importRunId = :run ORDER BY i.numberKey, i.id')
            ->setParameter('run', $runId)
            ->getResult();

        return $invoices;
    }

    public function dependents(Invoice $invoice): array
    {
        $checks = [
            UndoBlocker::PAYMENTS => 'SELECT COUNT(a.id) FROM '.PaymentAllocation::class.' a WHERE a.invoice = :id',
            UndoBlocker::SENDS => 'SELECT COUNT(s.id) FROM '.InvoiceSend::class.' s WHERE s.invoice = :id',
            UndoBlocker::CORRECTIONS => 'SELECT COUNT(c.id) FROM '.Invoice::class.' c WHERE c.correctedInvoiceId = :id',
            UndoBlocker::TAX_DOCUMENTS => 'SELECT COUNT(t.id) FROM '.Invoice::class.' t WHERE t.advanceInvoiceId = :id',
            UndoBlocker::DEDUCTIONS => 'SELECT COUNT(l.id) FROM '.InvoiceLine::class.' l WHERE l.deductsInvoiceId = :id',
        ];
        $reasons = [];
        foreach ($checks as $reason => $dql) {
            if (0 < (int) $this->em->createQuery($dql)->setParameter('id', $invoice->id())->getSingleScalarResult()) {
                $reasons[] = $reason;
            }
        }

        return $reasons;
    }

    public function remove(Invoice $invoice): void
    {
        $lines = $this->em->createQuery('SELECT l FROM '.InvoiceLine::class.' l WHERE l.invoice = :id')
            ->setParameter('id', $invoice->id())
            ->getResult();
        foreach ($lines as $line) {
            $this->em->remove($line);
        }
        $this->em->remove($invoice);
    }
}
