<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Invoicing\Application;

use App\Audit\Application\AuditActor;
use App\Invoicing\Domain\Invoice;
use App\Invoicing\Domain\Payment;
use App\Invoicing\Domain\PaymentAllocation;
use App\Invoicing\Domain\PaymentSource;
use App\Party\Application\TransactionalWork;
use App\Party\Domain\LegalEntity;
use App\Shared\Domain\Currency;
use App\Shared\Domain\Money;
use Psr\Clock\ClockInterface;

/**
 * Records a payment and splits it over invoices of one firm. What the request alone
 * decides is checked first; the rest under the locks of the invoices, taken in
 * ascending order of their ids so two payments over the same invoices never wait
 * for each other in a circle. The payment and its allocations are stored together
 * or not at all.
 *
 * A settlement writes off a small difference on a single invoice in Czech crowns,
 * at most MAX_SETTLEMENT minor units either way: the coins nobody pays by transfer.
 * Another currency has no such custom, so there it is refused.
 */
final readonly class RecordPayment
{
    public const int MAX_SETTLEMENT = 99;
    public const array SETTLEMENT_CURRENCIES = [Currency::CZK];

    public function __construct(
        private InvoiceStore $store,
        private InvoicePayments $payments,
        private InvoicingCountries $countries,
        private TransactionalWork $work,
        private ConflictRetry $retry,
        private AuditActor $actor,
        private ClockInterface $clock,
    ) {
    }

    /**
     * @return Payment the payment as stored; anything loaded before is detached
     *
     * @throws PaymentRefused nothing is stored
     * @throws UnknownInvoice also for an invoice of another tenant
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
            $firm = $this->firmOf($request, $invoices);
            if ($request->receivedOn->format('Y-m-d') > $this->todayOf($firm)->format('Y-m-d')) {
                throw new PaymentRefused(PaymentRefused::FUTURE_DATE);
            }

            $now = \DateTimeImmutable::createFromInterface($this->clock->now());
            $payment = new Payment($firm, $request->receivedOn, $request->amount, $request->method, $request->source, $request->bankMovementId, $request->note, $this->actor->userId(), $now);
            $this->payments->add($payment);
            foreach ($request->allocations as $allocation) {
                $this->payments->addAllocation(new PaymentAllocation($payment, $invoices[$allocation->invoiceId], $allocation->amount, $allocation->settlement));
            }

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
        foreach ($request->allocations as $allocation) {
            if (null !== $allocation->settlement && !$allocation->settlement->isZero() && !$this->settlementAllowed($request, $allocation->settlement)) {
                throw new PaymentRefused(PaymentRefused::SETTLEMENT_NOT_ALLOWED);
            }
        }
    }

    private function positive(Money $amount): bool
    {
        return !$amount->isZero() && !$amount->isNegative();
    }

    private function settlementAllowed(PaymentRequest $request, Money $settlement): bool
    {
        return 1 === \count($request->allocations)
            && \in_array($settlement->currency(), self::SETTLEMENT_CURRENCIES, true)
            && abs($settlement->minor()) <= self::MAX_SETTLEMENT;
    }

    /**
     * @param array<string, Invoice> $invoices
     */
    private function firmOf(PaymentRequest $request, array $invoices): LegalEntity
    {
        $firm = null;
        foreach ($invoices as $invoice) {
            if ($invoice->firm()->id() !== $request->firmId) {
                throw new PaymentRefused(PaymentRefused::WRONG_FIRM);
            }
            if ($invoice->isTrashed()) {
                throw new PaymentRefused(PaymentRefused::TRASHED);
            }
            if ($invoice->currency() !== $request->amount->currency()) {
                throw new PaymentRefused(PaymentRefused::CURRENCY_MISMATCH);
            }
            $firm = $invoice->firm();
        }

        return $firm ?? throw new PaymentRefused(PaymentRefused::NO_ALLOCATION);
    }

    private function todayOf(LegalEntity $firm): \DateTimeImmutable
    {
        $now = \DateTimeImmutable::createFromInterface($this->clock->now());
        try {
            return $now->setTimezone($this->countries->for($firm->address()->countryCode)->timezone());
        } catch (UnsupportedCountry) {
            return $now;
        }
    }
}
