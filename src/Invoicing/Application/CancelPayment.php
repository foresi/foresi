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
 * The source of the payment, a bank movement, is locked first, then the invoices:
 * the order of pairing and unpairing, so cancelling on the invoice and unpairing on
 * the bank tab wait for each other instead of deadlocking. Both are read before the
 * transaction, so the locks come first in it (on SQLite a read before the first write
 * lock would refuse to wait); neither the source nor the invoices of a payment ever
 * change, so what was read still holds under the locks.
 *
 * Its tax documents are locked last, and those the customer never got go to the
 * trash with the payment.
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
        private AdvanceDocuments $documents,
        private PaymentTaxDocumentTexts $texts,
    ) {
    }

    /**
     * @throws UnknownPayment also for a payment of another tenant
     */
    public function cancel(string $paymentId): void
    {
        $this->retry->run(function () use ($paymentId): void {
            $source = $this->payments->get($paymentId);
            $ids = $this->payments->invoiceIdsOf($paymentId);
            $taxDocuments = $this->documents->taxDocumentsOfPayment($paymentId);
            $this->work->run(function () use ($paymentId, $source, $ids, $taxDocuments): void {
                $this->release->lockSource($source);
                foreach ($ids as $id) {
                    $this->store->lock($id);
                }
                foreach ($taxDocuments as $id) {
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
                $this->dropTaxDocuments($taxDocuments);
            });
        });
    }

    /**
     * A tax document on the payment that never reached the customer goes to the
     * trash with it. One that did stays as it is and needs a corrective document,
     * which its page asks for; corrections are a step of their own.
     *
     * @param list<string> $ids
     */
    private function dropTaxDocuments(array $ids): void
    {
        $now = \DateTimeImmutable::createFromInterface($this->clock->now());
        foreach ($ids as $id) {
            $document = $this->store->get($id);
            if (!$document->isDelivered() && !$document->isTrashed()) {
                $document->trash($now, $this->actor->userId(), $this->texts->cancelledReason($document));
            }
        }
    }
}
