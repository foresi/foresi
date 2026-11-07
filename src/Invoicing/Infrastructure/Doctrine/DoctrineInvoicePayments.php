<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Invoicing\Infrastructure\Doctrine;

use App\Invoicing\Application\InvoicePaymentLine;
use App\Invoicing\Application\InvoicePayments;
use App\Invoicing\Application\UnknownPayment;
use App\Invoicing\Domain\DocumentKind;
use App\Invoicing\Domain\Invoice;
use App\Invoicing\Domain\Payment;
use App\Invoicing\Domain\PaymentAllocation;
use App\Shared\Domain\Money;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Every read goes through the ORM, so the tenant filter narrows the payments and
 * their allocations both.
 */
final readonly class DoctrineInvoicePayments implements InvoicePayments
{
    /**
     * What an invoice has been paid, for the list to use inside its own queries: the
     * identification variable of the invoice is "i", and the suffix keeps the aliases
     * of each use apart, as one query may hold it several times.
     */
    public static function paidOfInvoiceDql(string $suffix, string $invoice = 'i'): string
    {
        return \sprintf(
            '(SELECT COALESCE(SUM(pa%1$s.amountMinor + pa%1$s.settlementMinor), 0) FROM %2$s pa%1$s JOIN pa%1$s.payment pp%1$s WHERE pa%1$s.invoice = %3$s AND pp%1$s.cancelledAt IS NULL)',
            $suffix,
            PaymentAllocation::class,
            $invoice,
        );
    }

    /**
     * What an invoice asks for after its corrective documents not in the trash, for
     * the same queries: its payable and theirs in one subquery, as DQL cannot add to
     * a subquery.
     */
    public static function dueOfInvoiceDql(string $suffix, string $invoice = 'i'): string
    {
        return \sprintf(
            '(SELECT COALESCE(SUM(pd%1$s.payableMinor), 0) FROM %2$s pd%1$s WHERE pd%1$s.id = %3$s.id OR (pd%1$s.correctedInvoiceId = %3$s.id AND pd%1$s.deletedAt IS NULL))',
            $suffix,
            Invoice::class,
            $invoice,
        );
    }

    public function __construct(private EntityManagerInterface $em)
    {
    }

    public function paidOf(Invoice $invoice): Money
    {
        return Money::ofMinor($this->paidFor([$invoice->id()])[$invoice->id()] ?? 0, $invoice->currency());
    }

    public function paidFor(array $invoiceIds): array
    {
        if ([] === $invoiceIds) {
            return [];
        }
        /** @var list<array{invoice: string, paid: numeric-string|int}> $rows */
        $rows = $this->em->createQuery(
            'SELECT IDENTITY(a.invoice) AS invoice, SUM(a.amountMinor + a.settlementMinor) AS paid FROM '.PaymentAllocation::class.' a JOIN a.payment p '
            .'WHERE a.invoice IN (:ids) AND p.cancelledAt IS NULL GROUP BY a.invoice',
        )
            ->setParameter('ids', $invoiceIds)
            ->getArrayResult();
        $paid = [];
        foreach ($rows as $row) {
            $paid[$row['invoice']] = (int) $row['paid'];
        }

        return $paid;
    }

    public function correctionsOf(Invoice $invoice): Money
    {
        return Money::ofMinor($this->correctionsFor([$invoice->id()])[$invoice->id()] ?? 0, $invoice->currency());
    }

    public function correctionsFor(array $invoiceIds): array
    {
        if ([] === $invoiceIds) {
            return [];
        }
        /** @var list<array{invoice: string, corrections: numeric-string|int}> $rows */
        $rows = $this->em->createQuery(
            'SELECT c.correctedInvoiceId AS invoice, SUM(c.payableMinor) AS corrections FROM '.Invoice::class.' c '
            .'WHERE c.correctedInvoiceId IN (:ids) AND c.deletedAt IS NULL GROUP BY c.correctedInvoiceId',
        )
            ->setParameter('ids', $invoiceIds)
            ->getArrayResult();
        $corrections = [];
        foreach ($rows as $row) {
            $corrections[$row['invoice']] = (int) $row['corrections'];
        }

        return $corrections;
    }

    public function remainingOf(Invoice $invoice): Money
    {
        return $invoice->payable()->plus($this->correctionsOf($invoice))->minus($this->paidOf($invoice));
    }

    public function toRefundOf(Invoice $invoice): Money
    {
        $none = Money::ofMinor(0, $invoice->currency());
        if ($invoice->isTrashed() || !$invoice->kind()->isPayable()) {
            return $none;
        }
        // An advance no final invoice deducts goes back as a whole: its tax documents are corrected.
        if (DocumentKind::Advance === $invoice->kind()) {
            $paid = $this->paidOf($invoice);

            return null === $invoice->deductedByInvoiceId() && $paid->minor() > 0 ? $paid : $none;
        }
        $corrections = $this->correctionsOf($invoice);
        $remaining = $invoice->payable()->plus($corrections)->minus($this->paidOf($invoice));
        // Paid above what is due, after its corrections or without any; or advances beyond a final invoice.
        $refundable = $invoice->payable()->minor() > 0 || $invoice->deductsAdvances();

        return $refundable && $remaining->isNegative() ? Money::ofMinor(-$remaining->minor(), $remaining->currency()) : $none;
    }

    public function linesOf(Invoice $invoice): array
    {
        /** @var list<PaymentAllocation> $allocations */
        $allocations = $this->em->createQuery(
            'SELECT a, p FROM '.PaymentAllocation::class.' a JOIN a.payment p WHERE a.invoice = :invoice AND p.cancelledAt IS NULL ORDER BY p.receivedOn, p.createdAt, p.id, a.id',
        )
            ->setParameter('invoice', $invoice->id())
            ->getResult();

        return array_map(static function (PaymentAllocation $allocation): InvoicePaymentLine {
            $payment = $allocation->payment();

            return new InvoicePaymentLine(
                $payment->id(),
                $payment->receivedOn(),
                $allocation->amount(),
                $allocation->settlement(),
                $payment->method(),
                $payment->source(),
                $payment->bankMovementId(),
                $payment->createdBy(),
            );
        }, $allocations);
    }

    public function get(string $paymentId): Payment
    {
        // A query, not find(): find() answers from the identity map without the tenant filter.
        $payment = $this->em->createQuery('SELECT p FROM '.Payment::class.' p WHERE p.id = :id')->setParameter('id', $paymentId)->getOneOrNullResult();

        return $payment instanceof Payment ? $payment : throw new UnknownPayment();
    }

    public function hasPayments(Invoice $invoice): bool
    {
        $count = $this->em->createQuery('SELECT COUNT(a.id) FROM '.PaymentAllocation::class.' a JOIN a.payment p WHERE a.invoice = :invoice AND p.cancelledAt IS NULL')
            ->setParameter('invoice', $invoice->id())
            ->getSingleScalarResult();

        return 0 < (int) $count;
    }

    public function invoiceIdsOf(string $paymentId): array
    {
        /** @var list<string> $ids */
        $ids = $this->em->createQuery('SELECT DISTINCT IDENTITY(a.invoice) AS invoice FROM '.PaymentAllocation::class.' a WHERE a.payment = :payment ORDER BY invoice')
            ->setParameter('payment', $paymentId)
            ->getSingleColumnResult();

        return $ids;
    }

    public function invoiceNumbersOf(string $paymentId): array
    {
        /** @var list<array{id: string, number: string}> $rows */
        $rows = $this->em->createQuery('SELECT DISTINCT i.id AS id, i.number AS number FROM '.PaymentAllocation::class.' a JOIN a.invoice i WHERE a.payment = :payment ORDER BY i.number, i.id')
            ->setParameter('payment', $paymentId)
            ->getArrayResult();

        return array_column($rows, 'number', 'id');
    }

    public function add(Payment $payment): void
    {
        $this->em->persist($payment);
    }

    public function addAllocation(PaymentAllocation $allocation): void
    {
        $this->em->persist($allocation);
    }

    public function removeAllocationsOf(Payment $payment): void
    {
        /** @var list<PaymentAllocation> $allocations */
        $allocations = $this->em->createQuery('SELECT a FROM '.PaymentAllocation::class.' a WHERE a.payment = :payment')
            ->setParameter('payment', $payment->id())
            ->getResult();
        // One by one through the unit of work, so the audit log sees each go.
        foreach ($allocations as $allocation) {
            $this->em->remove($allocation);
        }
    }
}
