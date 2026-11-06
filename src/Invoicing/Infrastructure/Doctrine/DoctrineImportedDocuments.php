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
use App\Invoicing\Domain\Payment;
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

    public function findPayment(LegalEntity $firm, string $source, string $externalId): ?Payment
    {
        /** @var Payment|null $payment */
        $payment = $this->em->createQuery('SELECT p FROM '.Payment::class.' p WHERE p.firm = :firm AND p.importSource = :source AND p.importExternalId = :external')
            ->setParameter('firm', $firm->id())
            ->setParameter('source', $source)
            ->setParameter('external', $externalId)
            ->getOneOrNullResult();

        return $payment;
    }

    public function paymentsOfRun(string $runId): array
    {
        /** @var list<Payment> $payments */
        $payments = $this->em->createQuery('SELECT p FROM '.Payment::class.' p WHERE p.importRunId = :run ORDER BY p.receivedOn, p.id')
            ->setParameter('run', $runId)
            ->getResult();

        return $payments;
    }

    public function dependents(Invoice $invoice): array
    {
        return $this->reasons([
            UndoBlocker::PAYMENTS => 'SELECT COUNT(a.id) FROM '.PaymentAllocation::class.' a JOIN a.payment p WHERE a.invoice = :id AND '.$this->otherRun('p'),
            UndoBlocker::SENDS => 'SELECT COUNT(s.id) FROM '.InvoiceSend::class.' s WHERE s.invoice = :id',
            UndoBlocker::CORRECTIONS => 'SELECT COUNT(c.id) FROM '.Invoice::class.' c WHERE c.correctedInvoiceId = :id',
            UndoBlocker::TAX_DOCUMENTS => 'SELECT COUNT(t.id) FROM '.Invoice::class.' t WHERE t.advanceInvoiceId = :id AND '.$this->otherRun('t'),
            UndoBlocker::DEDUCTIONS => 'SELECT COUNT(l.id) FROM '.InvoiceLine::class.' l JOIN l.invoice d WHERE l.deductsInvoiceId = :id AND '.$this->otherRun('d'),
        ], $invoice->id(), $invoice->importRunId());
    }

    public function paymentDependents(Payment $payment): array
    {
        return $this->reasons([
            UndoBlocker::TAX_DOCUMENTS => 'SELECT COUNT(t.id) FROM '.Invoice::class.' t WHERE t.paymentId = :id AND '.$this->otherRun('t'),
        ], $payment->id(), $payment->importRunId());
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

    public function removePayment(Payment $payment): void
    {
        $allocations = $this->em->createQuery('SELECT a FROM '.PaymentAllocation::class.' a WHERE a.payment = :id')
            ->setParameter('id', $payment->id())
            ->getResult();
        foreach ($allocations as $allocation) {
            $this->em->remove($allocation);
        }
        $this->em->remove($payment);
    }

    /**
     * @param array<string, string> $checks DQL counting what stands on :id, by reason
     *
     * @return list<string>
     */
    private function reasons(array $checks, string $id, ?string $runId): array
    {
        $reasons = [];
        foreach ($checks as $reason => $dql) {
            $query = $this->em->createQuery($dql)->setParameter('id', $id);
            if (str_contains($dql, ':run')) {
                $query->setParameter('run', (string) $runId);
            }
            if (0 < (int) $query->getSingleScalarResult()) {
                $reasons[] = $reason;
            }
        }

        return $reasons;
    }

    /** What the run of the record did not bring: recorded here, or by another run. */
    private function otherRun(string $alias): string
    {
        return '('.$alias.'.importRunId IS NULL OR '.$alias.'.importRunId <> :run)';
    }
}
