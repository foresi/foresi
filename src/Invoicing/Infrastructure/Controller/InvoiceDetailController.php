<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Invoicing\Infrastructure\Controller;

use Foresi\Instance\Application\InstanceClock;
use Foresi\Invoicing\Application\CorrectInvoice;
use Foresi\Invoicing\Application\DocumentChain;
use Foresi\Invoicing\Application\InvoiceStatus;
use Foresi\Invoicing\Application\InvoicingNotAllowed;
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

        return $this->render('invoicing/show.html.twig', [
            'invoice' => $invoice,
            'title' => $this->translator->trans(self::TITLES[$invoice->kind()->value], ['number' => $invoice->number()]),
            'badge' => ['label' => $this->translator->trans('invoice.list.status.'.$status->value), 'tone' => $status->tone()],
            'facts' => $this->facts($invoice),
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
     * @return list<array{label: string, value: string, big?: bool}>
     */
    private function facts(Invoice $invoice): array
    {
        $date = $this->translator->trans('format.date');
        $taxPoint = $invoice->taxPointDate();
        $facts = [
            ['label' => $this->translator->trans('invoice.field.customer'), 'value' => $invoice->customer()->name],
            ['label' => $this->translator->trans('invoice.total.payable'), 'value' => $this->amounts->shown($invoice->payable()), 'big' => true],
            ['label' => $this->translator->trans('invoice.field.issueDate'), 'value' => $invoice->issueDate()->format($date)],
        ];
        if ($taxPoint instanceof \DateTimeImmutable) {
            $facts[] = ['label' => $this->translator->trans('invoice.field.taxPointDate'), 'value' => $taxPoint->format($date)];
        }
        $facts[] = ['label' => $this->translator->trans('invoice.field.dueDate'), 'value' => $invoice->dueDate()->format($date)];
        $facts[] = ['label' => $this->translator->trans('invoice.field.paymentMethod'), 'value' => $this->translator->trans('invoice.payment.'.$invoice->paymentMethod()->value)];
        if ('' !== $invoice->variableSymbol()) {
            $facts[] = ['label' => $this->translator->trans('invoice.field.variableSymbol'), 'value' => $invoice->variableSymbol()];
        }
        if ('' !== $invoice->orderNumber()) {
            $facts[] = ['label' => $this->translator->trans('invoice.field.orderNumber'), 'value' => $invoice->orderNumber()];
        }

        return $facts;
    }

    /**
     * @return array{text: string, quantity: string, price: string, rate: string, total: string}
     */
    private function line(InvoiceLine $line): array
    {
        $rate = $line->rate();

        return [
            'text' => $line->text(),
            'quantity' => trim($this->amounts->quantity($line->quantity()->toDecimal()).' '.$line->unit()),
            'price' => $this->amounts->shown($line->price()),
            'rate' => $rate instanceof \Foresi\Invoicing\Domain\Percent ? $this->amounts->trimmed($rate->decimal()).' %' : '',
            'total' => $this->amounts->shown($line->gross()),
        ];
    }
}
