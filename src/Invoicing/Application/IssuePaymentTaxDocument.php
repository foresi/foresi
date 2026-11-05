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
        private InvoicePayments $payments,
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
            return $this->savepoint->run(fn (): Invoice => $this->issue->issue($input, new PaymentTaxDocumentOrigin($advance->id(), $payment->id(), $this->documents)));
        } catch (IssueRefused) {
            return null;
        }
    }

    /**
     * Issues the document a payment did not get, by hand: a rule refused it then, or
     * the advance was told to issue one only after the payment.
     *
     * @throws IssueRefused   when the payment has its document already, no VAT arose on it, it is
     *                        not a live payment of the advance, or a rule refuses the document
     * @throws UnknownInvoice also for an advance of another tenant
     */
    public function byHand(string $advanceId, string $paymentId): Invoice
    {
        // A payment that has its document is refused by the origin, in the transaction.
        $advance = $this->store->get($advanceId);
        if (DocumentKind::Advance !== $advance->kind() || $advance->isTrashed() || !$this->taxation->of($advance)->arises) {
            throw new IssueRefused([new IssueProblem('document', 'invoice.problem.no_tax_on_receipt')]);
        }
        $lines = array_values(array_filter($this->payments->linesOf($advance), static fn (InvoicePaymentLine $line): bool => $line->paymentId === $paymentId));
        if ([] === $lines) {
            throw new IssueRefused([new IssueProblem('document', 'invoice.problem.payment_unknown')]);
        }
        $input = $this->input($this->payments->get($paymentId), $advance, $lines[0]->allocated);
        if (!$input instanceof InvoiceInput) {
            throw new IssueRefused([new IssueProblem('lines', 'invoice.problem.no_lines')]);
        }

        return $this->issue->issue($input, new PaymentTaxDocumentOrigin($advanceId, $paymentId, $this->documents));
    }

    /**
     * What the tax document is issued from; null when the advance has nothing to split.
     */
    private function input(Payment $payment, Invoice $advance, Money $received): ?InvoiceInput
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
