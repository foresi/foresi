<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Invoicing\Application;

use App\Invoicing\Domain\DocumentKind;
use App\Invoicing\Domain\Invoice;
use App\Invoicing\Domain\Payment;
use App\Party\Application\FirmBankAccounts;
use App\Shared\Domain\Money;

/**
 * Issues the tax document on a payment received on an advance invoice, where VAT
 * arises on receipt. It is the advance's document for that payment: customer,
 * symbols, order number and language of the advance; the day of receipt as issue
 * date, tax point and due date; and the amount received split over the rates of
 * the advance. It is paid by the payment, so nothing more is asked for.
 *
 * It runs inside the transaction that records the payment, at a savepoint of its
 * own: when a rule refuses the document, nothing of it is stored, the payment
 * stands, and the advance waits among the paid ones without a tax document.
 */
final readonly class IssuePaymentTaxDocument
{
    public function __construct(
        private IssueInvoice $issue,
        private AdvanceTaxation $taxation,
        private InvoiceStore $store,
        private PaymentTaxDocumentLines $lines,
        private PaymentTaxDocumentTexts $texts,
        private FirmBankAccounts $accounts,
        private Savepoint $savepoint,
        private AdvanceDocuments $documents,
    ) {
    }

    /**
     * Read from the store: a refusal before it started the session over.
     *
     * @param Money $received the part of the payment allocated to the advance
     *
     * @return Invoice|null the tax document; null when VAT does not arise, the payment has one already, or a rule refused it
     */
    public function forPayment(Payment $payment, string $advanceId, Money $received): ?Invoice
    {
        $advance = $this->store->get($advanceId);
        if (DocumentKind::Advance !== $advance->kind() || null !== $this->documents->taxDocumentOf($advanceId, $payment->id()) || !$this->taxation->of($advance)->arises) {
            return null;
        }
        $input = $this->input($payment, $advance, $received);
        if (!$input instanceof InvoiceInput) {
            return null;
        }
        try {
            return $this->savepoint->run(fn (): Invoice => $this->issue->issue($input, new PaymentTaxDocumentOrigin($advance->id(), $payment->id())));
        } catch (IssueRefused) {
            return null;
        }
    }

    /**
     * What the tax document is issued from; null when the advance has nothing to split.
     */
    public function input(Payment $payment, Invoice $advance, Money $received): ?InvoiceInput
    {
        $lines = $this->lines->of($this->store->lines($advance), $received, $this->texts->lineText($advance));
        if ([] === $lines) {
            return null;
        }
        $day = new \DateTimeImmutable($payment->receivedOn()->format('Y-m-d'));
        $firm = $advance->firm();
        $account = $advance->bankAccount();
        // The advance's account, while the firm still has it; else the primary one, if any: the document asks for no payment.
        if (null === $account || !\in_array($account, $this->accounts->ibansOf($firm), true)) {
            $account = $this->accounts->primaryOf($firm)?->iban();
        }

        return new InvoiceInput(
            $firm->id(),
            null,
            null,
            $advance->customerParty()->id(),
            $advance->customer(),
            $day,
            $day,
            $day,
            $payment->method(),
            $account,
            $advance->variableSymbol(),
            $advance->constantSymbol(),
            $advance->orderNumber(),
            $lines[0]->text,
            '',
            '',
            $advance->language(),
            $lines,
            kind: DocumentKind::PaymentTaxDocument,
        );
    }
}
