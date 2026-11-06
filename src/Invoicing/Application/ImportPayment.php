<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Invoicing\Application;

use App\Audit\Application\AuditActor;
use App\Invoicing\Domain\Payment;
use App\Invoicing\Domain\PaymentAllocation;
use App\Invoicing\Domain\PaymentSource;
use App\Party\Application\LegalEntities;
use App\Party\Application\TransactionalWork;
use App\Party\Application\UnknownLegalEntity;
use Psr\Clock\ClockInterface;

/**
 * Stores a payment another system recorded as a manual payment of the firm, on
 * its day, allocated whole to the one document it paid there, and marked as
 * imported. It is checked as RecordPayment checks a payment, but it takes no lock:
 * a lock starts the session over, which would detach what the import running it
 * holds, and an import writes off nothing, which is what the lock guards. Unlike a payment recorded here, it issues no tax document on an
 * advance and dispatches nothing: the source issued what followed it, which is
 * imported on its own, and nothing is sent.
 *
 * A source and an id there are imported once: the second time answers that it
 * was, and changes nothing.
 */
final readonly class ImportPayment
{
    public function __construct(
        private LegalEntities $firms,
        private InvoiceStore $store,
        private InvoicePayments $payments,
        private ImportedDocuments $imported,
        private InvoicingCountries $countries,
        private TransactionalWork $work,
        private ConflictRetry $retry,
        private AuditActor $actor,
        private ClockInterface $clock,
    ) {
    }

    /**
     * @throws UnknownLegalEntity also for a firm of another tenant
     * @throws UnknownInvoice     also for a document of another tenant
     * @throws UnsupportedCountry for a firm whose country Foresi does not invoice in
     */
    public function import(ImportedPaymentInput $import): ImportedPaymentResult
    {
        return $this->retry->run(fn (): ImportedPaymentResult => $this->work->run(function () use ($import): ImportedPaymentResult {
            $invoice = $this->store->get($import->invoiceId);
            $firm = $this->firms->get($import->firmId);
            $known = $this->imported->findPayment($firm, $import->source, $import->externalId);
            if ($known instanceof Payment) {
                return ImportedPaymentResult::alreadyImported($known->id());
            }
            $now = \DateTimeImmutable::createFromInterface($this->clock->now());
            $today = $now->setTimezone($this->countries->for($firm->address()->countryCode)->timezone())->format('Y-m-d');
            $refusal = match (true) {
                $import->amount->isZero() || $import->amount->isNegative() => PaymentRefused::NOT_POSITIVE,
                mb_strlen($import->note) > Payment::MAX_NOTE => PaymentRefused::NOTE_TOO_LONG,
                $invoice->firm()->id() !== $firm->id() => PaymentRefused::WRONG_FIRM,
                $invoice->isTrashed() => PaymentRefused::TRASHED,
                !$invoice->kind()->isPayable() => PaymentRefused::NOT_PAYABLE,
                $invoice->currency() !== $import->amount->currency() => PaymentRefused::CURRENCY_MISMATCH,
                $import->receivedOn->format('Y-m-d') > $today => PaymentRefused::FUTURE_DATE,
                default => null,
            };
            if (null !== $refusal) {
                return ImportedPaymentResult::refused($refusal);
            }
            $payment = new Payment($firm, $import->receivedOn, $import->amount, $import->method, PaymentSource::Manual, null, $import->note, $this->actor->userId(), $now);
            $payment->importedFrom($import->source, $import->externalId, $import->runId, $now);
            $this->payments->add($payment);
            $this->payments->addAllocation(new PaymentAllocation($payment, $invoice, $import->amount));

            return ImportedPaymentResult::imported($payment->id());
        }));
    }
}
