<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Invoicing\Application;

use Foresi\Audit\Application\AuditActor;
use Foresi\Invoicing\Contract\DocumentKind;
use Foresi\Invoicing\Contract\Event\PaymentRecorded;
use Foresi\Invoicing\Domain\Invoice;
use Foresi\Invoicing\Domain\Payment;
use Foresi\Invoicing\Domain\PaymentAllocation;
use Foresi\Invoicing\Domain\PaymentDirection;
use Foresi\Invoicing\Domain\PaymentSource;
use Foresi\Party\Application\InstanceFirm;
use Foresi\Party\Application\TransactionalWork;
use Foresi\Shared\Application\Event\EventRecorder;
use Foresi\Shared\Domain\Currency;
use Foresi\Shared\Domain\Money;
use Psr\Clock\ClockInterface;
use Psr\EventDispatcher\EventDispatcherInterface;

/**
 * Records a payment and splits it over invoices. What the request alone
 * decides is checked first; the rest under the locks of the invoices, taken in
 * ascending order of their ids so two payments over the same invoices never wait
 * for each other in a circle. The payment and its allocations are stored together
 * or not at all.
 *
 * A settlement writes off a small difference on a single invoice, and only the
 * difference that closes it exactly: what is payable less what is paid already
 * less this payment. How much may be written off, and in which currency, is the
 * custom of the firm's country; a country without one, or without a module,
 * allows none. Both are checked under the lock, against what is paid then.
 *
 * A payment of an advance invoice on which VAT arises gets its tax document in the
 * same transaction, one per advance it pays. A document a rule refuses is left
 * out and the payment stays: the advance then waits for its document by hand.
 *
 * A refund sends money back on invoices with something to refund, never more than
 * that, checked under their locks; it writes nothing off. A refund of an advance
 * issues no tax document: correcting the one on the payment is a step of its own,
 * which listens for AdvanceRefunded, dispatched in the same transaction.
 *
 * The same transaction records that the payment was recorded and, for each invoice
 * it makes paid, that it is paid.
 */
final readonly class RecordPayment
{
    public function __construct(
        private InvoiceStore $store,
        private InvoicePayments $payments,
        private InvoicingCountries $countries,
        private TransactionalWork $work,
        private ConflictRetry $retry,
        private AuditActor $actor,
        private ClockInterface $clock,
        private IssuePaymentTaxDocument $taxDocuments,
        private EventDispatcherInterface $events,
        private EventRecorder $recorder,
        private PaymentTransitions $transitions,
        private InstanceFirm $instanceFirm,
    ) {
    }

    /**
     * @return Payment the payment as stored; anything loaded before is detached
     *
     * @throws PaymentRefused nothing is stored
     * @throws UnknownInvoice
     */
    public function record(PaymentRequest $request): Payment
    {
        $this->check($request);
        $ids = array_values(array_unique(array_map(static fn (AllocationRequest $allocation): string => $allocation->invoiceId, $request->allocations)));
        sort($ids, \SORT_STRING);

        return $this->retry->run(fn (): Payment => $this->work->run(function () use ($request, $ids): Payment {
            foreach ($ids as $id) {
                $this->store->lock($id);
            }
            // Each lock starts the session over: read them all once every row is held.
            $invoices = [];
            foreach ($ids as $id) {
                $invoices[$id] = $this->store->get($id);
            }
            $this->checkInvoices($request, $invoices);
            $before = $this->transitions->before(array_values($invoices));
            if ($request->receivedOn->format('Y-m-d') > $this->today()->format('Y-m-d')) {
                throw new PaymentRefused(PaymentRefused::FUTURE_DATE);
            }
            foreach ($request->allocations as $allocation) {
                $this->checkSettlement($invoices[$allocation->invoiceId], $allocation);
                $this->checkRefund($request, $invoices[$allocation->invoiceId], $allocation);
            }

            $now = \DateTimeImmutable::createFromInterface($this->clock->now());
            $payment = new Payment($request->receivedOn, $request->amount, $request->method, $request->source, $request->bankMovementId, $request->note, $this->actor->userId(), $now, $request->direction);
            $this->payments->add($payment);
            foreach ($request->allocations as $allocation) {
                $this->payments->addAllocation(new PaymentAllocation($payment, $invoices[$allocation->invoiceId], $allocation->amount, $allocation->settlement));
            }
            $this->recorder->record(new PaymentRecorded(
                $payment->id(),
                $this->instanceFirm->get()->id(),
                EventFacts::amount($payment->amount()),
                $payment->amount()->currency()->value,
                EventFacts::day($payment->receivedOn()),
                EventFacts::direction($payment->direction()),
                $payment->source()->value,
                $ids,
            ));
            // The one path every payment takes, by hand or from the bank: an advance gets its tax document here.
            foreach ($request->allocations as $allocation) {
                if (DocumentKind::Advance !== $invoices[$allocation->invoiceId]->kind()) {
                    continue;
                }
                if ($payment->isRefund()) {
                    $this->events->dispatch(new AdvanceRefunded($payment->id(), $allocation->invoiceId, $allocation->amount));
                } else {
                    $this->taxDocuments->forPayment($payment, $allocation->invoiceId, $allocation->amount);
                }
            }
            $this->transitions->recordPaid(array_values($invoices), $before, $payment->id());

            return $payment;
        }));
    }

    private function check(PaymentRequest $request): void
    {
        if ([] === $request->allocations) {
            throw new PaymentRefused(PaymentRefused::NO_ALLOCATION);
        }
        if (mb_strlen($request->note) > Payment::MAX_NOTE) {
            throw new PaymentRefused(PaymentRefused::NOTE_TOO_LONG);
        }
        if ((PaymentSource::BankMovement === $request->source) !== (null !== $request->bankMovementId)) {
            throw new PaymentRefused(PaymentRefused::NO_MOVEMENT);
        }
        $currency = $request->amount->currency();
        $allocated = 0;
        foreach ($request->allocations as $allocation) {
            if (!$this->positive($request->amount) || !$this->positive($allocation->amount)) {
                throw new PaymentRefused(PaymentRefused::NOT_POSITIVE);
            }
            if ($allocation->amount->currency() !== $currency || (null !== $allocation->settlement && $allocation->settlement->currency() !== $currency)) {
                throw new PaymentRefused(PaymentRefused::CURRENCY_MISMATCH);
            }
            $allocated += $allocation->amount->minor();
        }
        if ($allocated !== $request->amount->minor()) {
            throw new PaymentRefused(PaymentRefused::AMOUNT_MISMATCH);
        }
        if ((1 !== \count($request->allocations) || PaymentDirection::Refund === $request->direction) && array_any($request->allocations, $this->settles(...))) {
            throw new PaymentRefused(PaymentRefused::SETTLEMENT_NOT_ALLOWED);
        }
    }

    private function settles(AllocationRequest $allocation): bool
    {
        return $allocation->settlement instanceof Money && !$allocation->settlement->isZero();
    }

    private function positive(Money $amount): bool
    {
        return !$amount->isZero() && !$amount->isNegative();
    }

    private function checkSettlement(Invoice $invoice, AllocationRequest $allocation): void
    {
        if (!$allocation->settlement instanceof Money || !$this->settles($allocation)) {
            return;
        }
        $settlement = $allocation->settlement->minor();
        $limit = $this->settlementLimit($allocation->settlement->currency());
        $closing = $invoice->payable()->minor() - $this->payments->paidOf($invoice)->minor() - $allocation->amount->minor();
        if (null === $limit || abs($settlement) > $limit || $settlement !== $closing) {
            throw new PaymentRefused(PaymentRefused::SETTLEMENT_NOT_ALLOWED);
        }
    }

    private function checkRefund(PaymentRequest $request, Invoice $invoice, AllocationRequest $allocation): void
    {
        if (PaymentDirection::Refund === $request->direction && $allocation->amount->minor() > $this->payments->toRefundOf($invoice)->minor()) {
            throw new PaymentRefused(PaymentRefused::REFUND_TOO_LARGE);
        }
    }

    private function settlementLimit(Currency $currency): ?int
    {
        try {
            return $this->countries->for($this->instanceFirm->get()->address()->countryCode)->settlementLimit($currency);
        } catch (UnsupportedCountry) {
            return null;
        }
    }

    /**
     * @param array<string, Invoice> $invoices
     */
    private function checkInvoices(PaymentRequest $request, array $invoices): void
    {
        if ([] === $invoices) {
            throw new PaymentRefused(PaymentRefused::NO_ALLOCATION);
        }
        foreach ($invoices as $invoice) {
            if ($invoice->isTrashed()) {
                throw new PaymentRefused(PaymentRefused::TRASHED);
            }
            if (!$invoice->kind()->isPayable()) {
                throw new PaymentRefused(PaymentRefused::NOT_PAYABLE);
            }
            if ($invoice->currency() !== $request->amount->currency()) {
                throw new PaymentRefused(PaymentRefused::CURRENCY_MISMATCH);
            }
        }
    }

    private function today(): \DateTimeImmutable
    {
        $now = \DateTimeImmutable::createFromInterface($this->clock->now());
        try {
            return $now->setTimezone($this->countries->for($this->instanceFirm->get()->address()->countryCode)->timezone());
        } catch (UnsupportedCountry) {
            return $now;
        }
    }
}
