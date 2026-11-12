<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Invoicing\Application;

use App\Invoicing\Contract\Event\InvoicePaid;
use App\Invoicing\Contract\Event\PaidState;
use App\Invoicing\Domain\Invoice;
use App\Shared\Application\Event\EventRecorder;

/**
 * Tells when a payment, or the cancelling of one, makes a payable document paid:
 * the state of each is taken before the change and compared with the state after
 * it. A document already paid or overpaid, and one that is not payable, such as a
 * tax document on payment, never moves into it. Call both inside the unit of work
 * of the change, with the invoices locked.
 */
final readonly class PaymentTransitions
{
    public function __construct(
        private InvoicePayments $payments,
        private EventRecorder $events,
    ) {
    }

    /**
     * @param list<Invoice> $invoices
     *
     * @return array<string, PaymentState> by invoice id; only the payable documents
     */
    public function before(array $invoices): array
    {
        $states = [];
        foreach ($invoices as $invoice) {
            if ($invoice->kind()->isPayable()) {
                $states[$invoice->id()] = $this->stateOf($invoice);
            }
        }

        return $states;
    }

    /**
     * @param list<Invoice>               $invoices
     * @param array<string, PaymentState> $before   as before() gave it
     */
    public function recordPaid(array $invoices, array $before, string $paymentId): void
    {
        // The states are read from the database: what the change stored has to be there.
        $this->payments->flush();
        foreach ($invoices as $invoice) {
            $was = $before[$invoice->id()] ?? null;
            if (!$invoice->kind()->isPayable() || !$was instanceof PaymentState || $this->isPaid($was)) {
                continue;
            }
            $state = $this->stateOf($invoice);
            if ($this->isPaid($state)) {
                $this->events->record(new InvoicePaid($invoice->id(), PaymentState::Paid === $state ? PaidState::Paid : PaidState::Overpaid, $paymentId));
            }
        }
    }

    private function stateOf(Invoice $invoice): PaymentState
    {
        return PaymentState::ofDocument($invoice->kind(), $invoice->payable(), $this->payments->paidOf($invoice), $this->payments->correctionsOf($invoice));
    }

    private function isPaid(PaymentState $state): bool
    {
        return PaymentState::Paid === $state || PaymentState::Overpaid === $state;
    }
}
