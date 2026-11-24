<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Invoicing\Infrastructure\Controller;

use Foresi\Instance\Application\InstanceClock;
use Foresi\Invoicing\Application\CorrectInvoice;
use Foresi\Invoicing\Application\DocumentChain;
use Foresi\Invoicing\Application\InvoicePaymentLine;
use Foresi\Invoicing\Application\InvoiceStatus;
use Foresi\Invoicing\Application\InvoicingNotAllowed;
use Foresi\Invoicing\Application\PaymentState;
use Foresi\Invoicing\Application\Query\InvoiceDetail;
use Foresi\Invoicing\Application\Query\InvoiceDetails;
use Foresi\Invoicing\Application\UnknownInvoice;
use Foresi\Invoicing\Domain\Invoice;
use Foresi\Invoicing\Domain\InvoiceLine;
use Foresi\Invoicing\Infrastructure\Form\AmountText;
use Foresi\Invoicing\Infrastructure\Form\PaymentPanel;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * An issued document, read only, for staff: what a row of a document list opens in the
 * sheet (docs/design/overlays.md), and the same URL as a page. Edit loads the form in
 * place. Every kind of document has one: invoice, advance, tax document, correction.
 */
final class InvoiceDetailController extends AbstractController
{
    /** The title of each kind of document, with its number, as the form's. */
    private const array TITLES = ['invoice' => 'invoice.title', 'advance' => 'advance.title', 'payment_tax_document' => 'tax_document.title', 'correction_document' => 'correction.title'];

    public function __construct(
        private readonly InvoiceDetails $details,
        private readonly PaymentPanel $panel,
        private readonly DocumentChain $chain,
        private readonly AmountText $amounts,
        private readonly InstanceClock $clock,
        private readonly TranslatorInterface $translator,
    ) {
    }

    #[Route('/invoices/{id}', name: 'app_invoice_show', requirements: ['id' => Requirement::UUID_V7], methods: ['GET'])]
    public function show(string $id): Response
    {
        try {
            $detail = $this->details->of($id);
        } catch (InvoicingNotAllowed) {
            throw new AccessDeniedHttpException('Only staff work with invoices.');
        } catch (UnknownInvoice) {
            throw new NotFoundHttpException();
        }
        $invoice = $detail->invoice;
        // Overdue by the instance's day, as the reminders count it (ADR 0017).
        $status = InvoiceStatus::ofInvoice($invoice, $this->clock->today(), $detail->paid, $detail->corrections);

        return $this->render('@Invoicing/show.html.twig', [
            'invoice' => $invoice,
            'title' => $this->translator->trans(self::TITLES[$invoice->kind()->value], ['number' => $invoice->number()]),
            'status' => ['label' => $this->translator->trans($status->labelKey($invoice->kind())), 'tone' => $status->tone()],
            'summary' => $this->summary($invoice),
            'details' => $this->details($detail),
            'lines' => array_map($this->line(...), $detail->lines),
            'totals' => [
                'net' => $this->amounts->shown($invoice->net()),
                'vat' => $invoice->vat()->isZero() ? null : $this->amounts->shown($invoice->vat()),
                'rounding' => $invoice->totalRounding()->isZero() ? null : $this->amounts->shown($invoice->totalRounding()),
                'payable' => $this->amounts->shown($invoice->payable()),
            ],
            'payments' => $invoice->isTrashed() ? null : $this->panel->of($invoice),
            'chains' => array_map(fn (array $chain): array => ['payments' => array_map(fn (array $payment): array => ['amount' => $this->amounts->shown($payment['amount'])] + $payment, $chain['payments'])] + $chain, $this->chain->of($invoice)),
            'standsAlone' => $invoice->kind()->standsAlone(),
            'corrects' => CorrectInvoice::corrects($invoice),
            'listRoute' => InvoiceListController::ROUTES[$invoice->kind()->value],
        ]);
    }

    /**
     * What a reader looks for first (Stripe's invoice detail, Fakturoid): whom it bills, with the
     * address and identifiers as on the document, and how much is due by when.
     *
     * @return array{customer: string, address: string, ids: string, payable: string, dueDate: string}
     */
    private function summary(Invoice $invoice): array
    {
        $customer = $invoice->customer();
        $address = $customer->address;
        $ids = [];
        if (null !== $customer->registrationNumber && '' !== $customer->registrationNumber) {
            $ids[] = $this->translator->trans('invoice.field.registrationNumber').' '.$customer->registrationNumber;
        }
        if (null !== $customer->vatId && '' !== $customer->vatId) {
            $ids[] = $this->translator->trans('invoice.field.vatId').' '.$customer->vatId;
        }

        return [
            'customer' => $customer->name,
            'address' => implode(', ', array_filter([$address->street, trim($address->postalCode.' '.$address->city)], static fn (string $part): bool => '' !== $part)),
            'ids' => implode(' · ', $ids),
            'payable' => $this->amounts->shown($invoice->payable()),
            'dueDate' => $invoice->dueDate()->format($this->translator->trans('format.date')),
        ];
    }

    /**
     * The dates and symbols, a label and a value each (Ui:DescriptionList), in the order of the
     * form's "Dates and payment". When it was sent and when it was paid in full follow the due
     * date once they happened, as Stripe's invoice detail lists its finalized and paid dates:
     * the status says where it stands, the dates when it got there.
     *
     * @return list<array{label: string, value: string}>
     */
    private function details(InvoiceDetail $detail): array
    {
        $invoice = $detail->invoice;
        $date = $this->translator->trans('format.date');
        $taxPoint = $invoice->taxPointDate();
        $details = [['label' => $this->translator->trans('invoice.field.issueDate'), 'value' => $invoice->issueDate()->format($date)]];
        if ($taxPoint instanceof \DateTimeImmutable) {
            $details[] = ['label' => $this->translator->trans('invoice.field.taxPointDate'), 'value' => $taxPoint->format($date)];
        }
        $details[] = ['label' => $this->translator->trans('invoice.field.dueDate'), 'value' => $invoice->dueDate()->format($date)];
        $sentAt = $invoice->deliveredAt();
        if ($sentAt instanceof \DateTimeImmutable) {
            $details[] = ['label' => $this->translator->trans('invoice.field.sentAt'), 'value' => $sentAt->setTimezone($this->clock->timezone())->format($date)];
        }
        $paidAt = $this->paidAt($detail);
        if ($paidAt instanceof \DateTimeImmutable) {
            $details[] = ['label' => $this->translator->trans('invoice.field.paidAt'), 'value' => $paidAt->format($date)];
        }
        $details[] = ['label' => $this->translator->trans('invoice.field.paymentMethod'), 'value' => $this->translator->trans('invoice.payment.'.$invoice->paymentMethod()->value)];
        if ('' !== $invoice->variableSymbol()) {
            $details[] = ['label' => $this->translator->trans('invoice.field.variableSymbol'), 'value' => $invoice->variableSymbol()];
        }
        if ('' !== $invoice->orderNumber()) {
            $details[] = ['label' => $this->translator->trans('invoice.field.orderNumber'), 'value' => $invoice->orderNumber()];
        }

        return $details;
    }

    /**
     * The day the payment that settled it came, the newest of its payments, while it is paid in
     * full; null while something is left to pay, and for a document never paid.
     */
    private function paidAt(InvoiceDetail $detail): ?\DateTimeImmutable
    {
        $invoice = $detail->invoice;
        if ([] === $detail->payments || !PaymentState::ofDocument($invoice->kind(), $invoice->payable(), $detail->paid, $detail->corrections)->isSettled()) {
            return null;
        }

        return max(array_map(static fn (InvoicePaymentLine $payment): \DateTimeImmutable => $payment->receivedOn, $detail->payments));
    }

    /**
     * @return array{id: string, text: string, quantity: string, price: string, rate: string, total: string}
     */
    private function line(InvoiceLine $line): array
    {
        $rate = $line->rate();

        return [
            'id' => $line->id(),
            'text' => $line->text(),
            'quantity' => trim($this->amounts->quantity($line->quantity()->toDecimal()).' '.$line->unit()),
            'price' => $this->amounts->shown($line->price()),
            'rate' => $rate instanceof \Foresi\Invoicing\Contract\Percent ? $this->amounts->trimmed($rate->decimal()).' %' : '',
            'total' => $this->amounts->shown($line->gross()),
        ];
    }
}
