<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Invoicing\Infrastructure\Form;

use App\Invoicing\Application\InvoicePaymentLine;
use App\Invoicing\Application\InvoicePayments;
use App\Invoicing\Application\PaymentState;
use App\Invoicing\Domain\Invoice;
use App\Invoicing\Domain\PaymentSource;
use App\Shared\Domain\Money;
use Symfony\Component\Routing\Exception\RouteNotFoundException;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * The payments of a stored invoice as its panel shows them, as iDoklad's card
 * lists them: when, how much and how, oldest first, with what is paid and what is
 * left. A bank payment says so and links to the movement it came from on the bank
 * screen when the bank module is there; Invoicing knows that screen by its route only.
 *
 * @phpstan-type PanelLine array{id: string, date: string, datetime: string, amount: string, settlement: ?string, method: string, bank: bool, movement: ?string, confirm: array{title: string, text: string, accept: string, cancel: string, tone: string}, label: string}
 * @phpstan-type Panel array{lines: list<PanelLine>, paid: string, remaining: ?string, overpaid: ?string, payable: bool, settled: bool}
 */
final readonly class PaymentPanel
{
    public function __construct(
        private InvoicePayments $payments,
        private AmountText $amounts,
        private TranslatorInterface $translator,
        private UrlGeneratorInterface $urls,
    ) {
    }

    /**
     * The movement on the bank screen; none without the bank module.
     */
    private function movementHref(string $movementId): ?string
    {
        try {
            return $this->urls->generate('app_bank_movements', ['movement' => $movementId]);
        } catch (RouteNotFoundException) {
            return null;
        }
    }

    /**
     * @return Panel
     */
    public function of(Invoice $invoice): array
    {
        $paid = $this->payments->paidOf($invoice);
        $remaining = $invoice->payable()->minus($paid);
        $state = PaymentState::of($invoice->payable(), $paid);
        $format = $this->translator->trans('format.date');

        return [
            'lines' => array_map(fn (InvoicePaymentLine $line): array => $this->line($invoice, $line, $format), $this->payments->linesOf($invoice)),
            'paid' => $this->amounts->shown($paid),
            'remaining' => $remaining->isNegative() || $remaining->isZero() ? null : $this->amounts->shown($remaining),
            'overpaid' => $remaining->isNegative() ? $this->amounts->shown(Money::ofMinor(-$remaining->minor(), $remaining->currency())) : null,
            // Nothing to pay on it, or in the trash: nothing to record either.
            'payable' => !$invoice->isTrashed() && !$invoice->payable()->isNegative() && !$invoice->payable()->isZero(),
            'settled' => $state->isSettled(),
        ];
    }

    /**
     * A payment that pays other invoices too goes from all of them: the
     * confirmation names them, so nobody unpays an invoice they are not looking at
     * unawares.
     *
     * @return PanelLine
     */
    private function line(Invoice $invoice, InvoicePaymentLine $line, string $format): array
    {
        $amount = $this->amounts->shown($line->allocated);
        $date = $line->receivedOn->format($format);
        $bank = PaymentSource::BankMovement === $line->source;
        $others = array_values(array_diff_key($this->payments->invoiceNumbersOf($line->paymentId), [$invoice->id() => true]));
        $text = [] === $others
            ? $this->translator->trans($bank ? 'invoice.payment.panel.remove_text_bank' : 'invoice.payment.panel.remove_text')
            : $this->translator->trans($bank ? 'invoice.payment.panel.remove_text_shared_bank' : 'invoice.payment.panel.remove_text_shared', ['count' => \count($others), 'numbers' => implode(', ', $others)]);

        return [
            'id' => $line->paymentId,
            'date' => $date,
            'datetime' => $line->receivedOn->format('Y-m-d'),
            'amount' => $amount,
            'settlement' => $line->settlement->isZero() ? null : $this->translator->trans('invoice.payment.panel.written_off', ['amount' => $this->amounts->shown($line->settlement)]),
            'method' => $bank ? $this->translator->trans('invoice.payment.panel.bank') : $this->translator->trans('invoice.payment.'.$line->method->value),
            'bank' => $bank,
            'movement' => $bank && null !== $line->bankMovementId ? $this->movementHref($line->bankMovementId) : null,
            'label' => $this->translator->trans('invoice.payment.panel.remove_label', ['amount' => $amount, 'date' => $date]),
            'confirm' => [
                'title' => $this->translator->trans('invoice.payment.panel.remove_confirm', ['amount' => $amount]),
                'text' => $text,
                'accept' => $this->translator->trans('invoice.payment.panel.remove_accept'),
                'cancel' => $this->translator->trans('invoice.payment.panel.keep'),
                'tone' => 'danger',
            ],
        ];
    }
}
