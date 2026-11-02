<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Invoicing\Application;

use App\Audit\Application\AuditActor;
use App\Invoicing\Domain\PaymentSource;
use App\Party\Application\TransactionalWork;
use Psr\Clock\ClockInterface;

/**
 * Cancels a payment: its allocations go, so the invoices it paid are as unpaid as
 * before, and the payment stays with who cancelled it and when. Under the locks of
 * those invoices, in the order RecordPayment takes them, so it never crosses a
 * payment being recorded. Cancelling twice is cancelling once: the second call
 * changes nothing and tells nobody.
 *
 * The invoices are read before the transaction, so the locks come first in it; a
 * payment cannot gain allocations, so the locked ones cover what is there.
 */
final readonly class CancelPayment
{
    public function __construct(
        private InvoicePayments $payments,
        private InvoiceStore $store,
        private TransactionalWork $work,
        private ConflictRetry $retry,
        private AuditActor $actor,
        private ClockInterface $clock,
        private PaymentSourceRelease $release,
    ) {
    }

    /**
     * @throws UnknownPayment also for a payment of another tenant
     */
    public function cancel(string $paymentId): void
    {
        $this->retry->run(function () use ($paymentId): void {
            $ids = $this->payments->invoiceIdsOf($paymentId);
            $this->work->run(function () use ($paymentId, $ids): void {
                foreach ($ids as $id) {
                    $this->store->lock($id);
                }
                $payment = $this->payments->get($paymentId);
                if ($payment->isCancelled()) {
                    return;
                }
                $this->payments->removeAllocationsOf($payment);
                $payment->cancel(\DateTimeImmutable::createFromInterface($this->clock->now()), $this->actor->userId());
                if (PaymentSource::BankMovement === $payment->source()) {
                    $this->release->released($payment);
                }
            });
        });
    }
}
