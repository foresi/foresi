<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Invoicing\Infrastructure\Doctrine;

use Doctrine\ORM\EntityManagerInterface;
use Foresi\Invoicing\Application\AllocationRequest;
use Foresi\Invoicing\Application\CancelPayment;
use Foresi\Invoicing\Application\InvoicePayments;
use Foresi\Invoicing\Application\InvoiceStore;
use Foresi\Invoicing\Application\InvoicingCountries;
use Foresi\Invoicing\Application\PaymentRefused;
use Foresi\Invoicing\Application\PaymentRequest;
use Foresi\Invoicing\Application\RecordPayment;
use Foresi\Invoicing\Application\UnknownInvoice;
use Foresi\Invoicing\Application\UnsupportedCountry;
use Foresi\Invoicing\Contract\BankPayment;
use Foresi\Invoicing\Contract\BankPaymentPart;
use Foresi\Invoicing\Contract\BankPaymentRefused;
use Foresi\Invoicing\Contract\BankPayments;
use Foresi\Invoicing\Contract\PaymentMethod;
use Foresi\Invoicing\Domain\Payment;
use Foresi\Invoicing\Domain\PaymentAllocation;
use Foresi\Invoicing\Domain\PaymentDirection;
use Foresi\Invoicing\Domain\PaymentSource;
use Foresi\Party\Application\InstanceFirm;
use Foresi\Shared\Domain\Currency;

/**
 * Bank payments through RecordPayment and CancelPayment, so a payment from the
 * bank follows the rules of any other; a refusal comes back with its reason and
 * the translation key the payment screens use.
 */
final readonly class DoctrineBankPayments implements BankPayments
{
    public function __construct(
        private EntityManagerInterface $em,
        private RecordPayment $record,
        private CancelPayment $cancel,
        private InvoiceStore $store,
        private InvoicePayments $payments,
        private InvoicingCountries $countries,
        private InstanceFirm $firm,
    ) {
    }

    public function lock(string $invoiceId): void
    {
        try {
            $this->store->lock($invoiceId);
        } catch (UnknownInvoice) {
            throw self::unknown();
        }
    }

    public function isSettled(string $invoiceId, bool $refund): bool
    {
        try {
            $invoice = $this->store->get($invoiceId);
        } catch (UnknownInvoice) {
            throw self::unknown();
        }

        return ($refund ? $this->payments->toRefundOf($invoice) : $this->payments->remainingOf($invoice))->minor() <= 0;
    }

    public function record(BankPayment $payment): string
    {
        try {
            return $this->record->record(new PaymentRequest(
                $payment->receivedOn,
                $payment->amount,
                PaymentMethod::BankTransfer,
                PaymentSource::BankMovement,
                $payment->bankMovementId,
                '',
                array_map(static fn (BankPaymentPart $part): AllocationRequest => new AllocationRequest($part->invoiceId, $part->amount, $part->settlement), $payment->parts),
                $payment->refund ? PaymentDirection::Refund : PaymentDirection::Incoming,
            ))->id();
        } catch (PaymentRefused $refused) {
            throw new BankPaymentRefused($refused->reason, 'invoice.payment.refused.'.$refused->reason, ['max' => Payment::MAX_NOTE]);
        } catch (UnknownInvoice) {
            throw self::unknown();
        }
    }

    public function cancel(string $paymentId): void
    {
        $this->cancel->cancel($paymentId);
    }

    public function hasLivePayment(string $bankMovementId): bool
    {
        $count = $this->em->createQuery('SELECT COUNT(p.id) FROM '.Payment::class.' p WHERE p.bankMovementId = :movement AND p.cancelledAt IS NULL')
            ->setParameter('movement', $bankMovementId)
            ->getSingleScalarResult();

        return (int) $count > 0;
    }

    public function invoiceNumbers(array $paymentIds): array
    {
        if ([] === $paymentIds) {
            return [];
        }
        /** @var list<array{payment: string, id: string, number: string}> $rows */
        $rows = $this->em->createQuery('SELECT IDENTITY(a.payment) AS payment, i.id AS id, i.number AS number FROM '.PaymentAllocation::class.' a JOIN a.invoice i WHERE a.payment IN (:payments) ORDER BY i.number, i.id')
            ->setParameter('payments', $paymentIds)
            ->getArrayResult();
        $invoices = [];
        foreach ($rows as $row) {
            $invoices[$row['payment']][$row['id']] = $row['number'];
        }

        return $invoices;
    }

    public function settlementLimit(Currency $currency): ?int
    {
        try {
            return $this->countries->for($this->firm->get()->address()->countryCode)->settlementLimit($currency);
        } catch (UnsupportedCountry) {
            return null;
        }
    }

    private static function unknown(): BankPaymentRefused
    {
        return new BankPaymentRefused(BankPaymentRefused::UNKNOWN_INVOICE, null);
    }
}
