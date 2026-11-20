<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Purchasing\Application;

use Foresi\Instance\Application\InstanceClock;
use Foresi\Purchasing\Contract\Event\ReceivedDocumentPaid;
use Foresi\Purchasing\Domain\PaymentMethod;
use Foresi\Purchasing\Domain\ReceivedDocument;
use Foresi\Purchasing\Domain\ReceivedDocumentPayment;
use Foresi\Purchasing\Domain\ReceivedDocumentRefused;
use Foresi\Shared\Application\Event\EventRecorder;
use Foresi\Shared\Domain\Money;
use Psr\Clock\ClockInterface;

/**
 * Records the payments of a recorded received document (decision C3 of the
 * cash flow spec): one payment of an amount on a day, or "mark as paid", which
 * pays all that remains today, in the instance's zone. A payment may not be more
 * than what remains, and one recorded by mistake can be removed. The payment that leaves nothing to pay records
 * purchasing.received_document.paid. A document in the trash, a draft and a
 * document paid in full take no payment; a closed month does not stop one, as a
 * payment changes nothing the VAT reports read.
 */
final readonly class PayReceivedDocument
{
    public function __construct(
        private PurchasingAccess $access,
        private PurchasingUnit $unit,
        private ReceivedDocuments $documents,
        private ReceivedDocumentPayments $payments,
        private EventRecorder $events,
        private InstanceClock $today,
        private ClockInterface $clock,
    ) {
    }

    /**
     * @throws PurchasingNotAllowed    for anyone but the owners and members of the organisation
     * @throws UnknownReceivedDocument
     * @throws ReceivedDocumentRefused with TRASHED, NOT_RECORDED, PAID, OVERPAID, PAYMENT_AMOUNT or BANK_MOVEMENT_INVALID
     */
    public function pay(string $documentId, \DateTimeImmutable $paidOn, Money $amount, PaymentMethod $method, ?string $bankMovementId = null): ReceivedDocumentPayment
    {
        return $this->record($documentId, static fn (): array => [$paidOn, $amount], $method, $bankMovementId);
    }

    /**
     * Pays what remains, today.
     *
     * @throws PurchasingNotAllowed    for anyone but the owners and members of the organisation
     * @throws UnknownReceivedDocument
     * @throws ReceivedDocumentRefused with TRASHED, NOT_RECORDED, PAID or BANK_MOVEMENT_INVALID
     */
    public function markPaid(string $documentId, PaymentMethod $method, ?string $bankMovementId = null): ReceivedDocumentPayment
    {
        return $this->record($documentId, fn (PaymentBalance $balance): array => [$this->today->today(), $balance->remaining], $method, $bankMovementId);
    }

    /**
     * Removes a payment recorded by mistake; the audit log keeps it. A document
     * paid in full before stays so in the event feed, which has no "unpaid".
     *
     * @throws PurchasingNotAllowed    for anyone but the owners and members of the organisation
     * @throws UnknownReceivedDocument also for a payment it does not have
     * @throws ReceivedDocumentRefused with TRASHED
     */
    public function remove(string $documentId, string $paymentId): void
    {
        $this->access->denyUnlessStaff();
        $this->unit->run(function () use ($documentId, $paymentId): void {
            $document = $this->documents->lock($documentId);
            if ($document->isTrashed()) {
                throw new ReceivedDocumentRefused(ReceivedDocumentRefused::TRASHED);
            }
            foreach ($this->payments->ofDocument($document->id()) as $payment) {
                if ($payment->id() === $paymentId) {
                    $this->payments->remove($payment);

                    return;
                }
            }

            throw new UnknownReceivedDocument('The received document has no payment with this id. Read its payments with foresi_list_received_document_payments.');
        });
    }

    /**
     * @param callable(PaymentBalance): array{\DateTimeImmutable, Money} $payment the day and the amount
     */
    private function record(string $documentId, callable $payment, PaymentMethod $method, ?string $bankMovementId): ReceivedDocumentPayment
    {
        $userId = $this->access->denyUnlessStaff();

        return $this->unit->run(function () use ($documentId, $payment, $method, $bankMovementId, $userId): ReceivedDocumentPayment {
            $document = $this->documents->lock($documentId);
            if ($document->isTrashed()) {
                throw new ReceivedDocumentRefused(ReceivedDocumentRefused::TRASHED);
            }
            if (!$document->isRecorded()) {
                throw new ReceivedDocumentRefused(ReceivedDocumentRefused::NOT_RECORDED);
            }
            $balance = $this->balance($document);
            if ($balance->remaining->isZero()) {
                throw new ReceivedDocumentRefused(ReceivedDocumentRefused::PAID);
            }
            [$paidOn, $amount] = $payment($balance);
            $paid = new ReceivedDocumentPayment($document, $paidOn, $amount, $method, $bankMovementId, $userId, \DateTimeImmutable::createFromInterface($this->clock->now()));
            if ($amount->minor() > $balance->remaining->minor()) {
                throw new ReceivedDocumentRefused(ReceivedDocumentRefused::OVERPAID);
            }
            $this->payments->add($paid);
            if ($amount->minor() === $balance->remaining->minor()) {
                $this->events->record(new ReceivedDocumentPaid($document->id(), $this->access->firm()->id(), $paid->id(), $paid->paidOn()->format('Y-m-d')));
            }

            return $paid;
        });
    }

    private function balance(ReceivedDocument $document): PaymentBalance
    {
        $sum = $this->payments->sums([$document->id()])[$document->id()] ?? null;

        return PaymentBalance::of($document, $sum['paid'] ?? 0, $sum['last'] ?? null);
    }
}
