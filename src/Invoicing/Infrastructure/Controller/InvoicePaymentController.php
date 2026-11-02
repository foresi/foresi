<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Invoicing\Infrastructure\Controller;

use App\Identity\Application\StaffCheck;
use App\Invoicing\Application\AllocationRequest;
use App\Invoicing\Application\CancelPayment;
use App\Invoicing\Application\InvoicePaymentLine;
use App\Invoicing\Application\InvoicePayments;
use App\Invoicing\Application\InvoiceStore;
use App\Invoicing\Application\InvoicingCountries;
use App\Invoicing\Application\PaymentRefused;
use App\Invoicing\Application\PaymentRequest;
use App\Invoicing\Application\RecordPayment;
use App\Invoicing\Application\UnknownInvoice;
use App\Invoicing\Application\UnknownPayment;
use App\Invoicing\Application\UnsupportedCountry;
use App\Invoicing\Domain\Invoice;
use App\Invoicing\Domain\Payment;
use App\Invoicing\Domain\PaymentSource;
use App\Invoicing\Infrastructure\Form\AmountText;
use App\Party\Domain\PaymentMethod;
use App\Shared\Domain\Currency;
use App\Shared\Domain\Money;
use App\Shared\Infrastructure\Http\SubmissionTokens;
use App\Shared\Infrastructure\Http\TypedNumber;
use Psr\Clock\ClockInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Component\Translation\LocaleSwitcher;
use Symfony\Component\Uid\Uuid;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Paying an invoice by hand, for staff, as iDoklad's Pay form has it: the date,
 * the amount with what is still to pay filled in, the method and a note. From the
 * list and the invoice it opens as a small dialog over the page; the same URL is a
 * page of its own without JavaScript. A part payment is fine, and so is more than
 * is due: the dialog says by how much it will overpay and records it all the same.
 * A one-time token makes a double click record one payment. A payment is removed
 * from the invoice, where its panel lists them; it stays, cancelled, in the log.
 *
 * A difference of a few coins either way can be written off so the invoice closes
 * exactly, as far as the issuer's country allows; a box offers it only then.
 *
 * @phpstan-type Values array{receivedOn: string, amount: string, method: string, note: string, settle: bool}
 */
final class InvoicePaymentController extends AbstractController
{
    public const string FORM_TOKEN = 'invoice_payment';
    private const string AMOUNT = '/^\d{1,13}(?:\.\d{1,2})?$/D';
    private const string NEGATIVE = '/^-\d{1,13}(?:\.\d{1,2})?$/D';
    private const string DATE = '/^\d{4}-\d{2}-\d{2}$/D';
    private const string FORM = 'form';

    public function __construct(
        private readonly InvoiceStore $store,
        private readonly InvoicePayments $payments,
        private readonly RecordPayment $record,
        private readonly CancelPayment $cancelling,
        private readonly InvoicingCountries $countries,
        private readonly SubmissionTokens $tokens,
        private readonly StaffCheck $staff,
        private readonly AmountText $amounts,
        private readonly TranslatorInterface $translator,
        private readonly ClockInterface $clock,
        private readonly LocaleSwitcher $locales,
    ) {
    }

    #[Route('/invoices/{id}/pay', name: 'app_invoice_pay', requirements: ['id' => Requirement::UUID_V7], methods: ['GET', 'POST'])]
    public function pay(string $id, Request $request): Response
    {
        $this->denyUnlessStaff();
        $invoice = $this->invoice($id);
        $post = $request->isMethod('POST');
        $return = $post ? $request->request->getString('return') : $request->query->getString('return');
        if ($post) {
            $this->checkToken($request);
        }
        $token = $request->request->getString('_submission');
        if ($post && $this->tokens->isIssued($token) && null !== $this->tokens->createdId($token)) {
            $this->addFlash('warning', $this->translator->trans('invoice.payment.flash.already_recorded'));

            return $this->toList($return);
        }
        if ($invoice->isTrashed() || !$this->positive($invoice->payable())) {
            $this->addFlash('error', $this->translator->trans($invoice->isTrashed() ? 'invoice.payment.trashed' : 'invoice.payment.nothing_due', ['number' => $invoice->number()]));

            return $this->toList($return);
        }
        if (!$post) {
            return $this->page($invoice, $this->prefilled($invoice), [], $this->tokens->issue(), $return, Response::HTTP_OK);
        }

        $values = [
            'receivedOn' => trim($request->request->getString('receivedOn')),
            'amount' => trim($request->request->getString('amount')),
            'method' => $request->request->getString('method'),
            'note' => trim($request->request->getString('note')),
            'settle' => $request->request->getBoolean('settle'),
        ];
        if (!$this->tokens->isIssued($token)) {
            // The token fell out of the session: nothing is lost, the next try goes through.
            return $this->page($invoice, $values, [self::FORM => $this->translator->trans('party.error.expired')], $this->tokens->issue(), $return, Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $errors = [];
        $receivedOn = $this->date($values['receivedOn'], $invoice, $errors);
        $amount = $this->amount($values['amount'], $invoice->currency(), $this->locales->getLocale(), $errors);
        $method = PaymentMethod::tryFrom($values['method']);
        if (!$method instanceof PaymentMethod) {
            $errors['method'] = $this->translator->trans('invoice.payment.error.method');
        }
        if (mb_strlen($values['note']) > Payment::MAX_NOTE) {
            $errors['note'] = $this->translator->trans('invoice.payment.error.note_too_long', ['max' => Payment::MAX_NOTE]);
        }
        $settlement = $amount instanceof Money && $values['settle'] ? $this->settlement($invoice, $amount, $errors) : null;
        if ([] !== $errors || !$receivedOn instanceof \DateTimeImmutable || !$amount instanceof Money || !$method instanceof PaymentMethod) {
            return $this->page($invoice, $values, $errors, $token, $return, Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        try {
            $payment = $this->record->record(new PaymentRequest($invoice->firm()->id(), $receivedOn, $amount, $method, PaymentSource::Manual, null, $values['note'], [new AllocationRequest($invoice->id(), $amount, $settlement)]));
        } catch (PaymentRefused $refused) {
            if (PaymentRefused::TRASHED === $refused->reason) {
                $this->addFlash('error', $this->translator->trans('invoice.payment.trashed', ['number' => $invoice->number()]));

                return $this->toList($return);
            }

            return $this->page($this->invoice($id), $values, $this->refusal($refused), $token, $return, Response::HTTP_UNPROCESSABLE_ENTITY);
        }
        $this->tokens->complete($token, $payment->id());

        $paid = $this->invoice($id);
        $remaining = $this->payments->remainingOf($paid);
        $recorded = ['amount' => $this->amounts->shown($amount), 'number' => $paid->number()];
        if ($remaining->isNegative()) {
            $this->addFlash('warning', $this->translator->trans('invoice.payment.flash.overpaid', $recorded + ['over' => $this->amounts->shown(Money::ofMinor(-$remaining->minor(), $remaining->currency()))]));
        } elseif ($settlement instanceof Money) {
            $this->addFlash('success', $this->translator->trans('invoice.payment.flash.settled', $recorded + ['difference' => $this->amounts->shown(Money::ofMinor(abs($settlement->minor()), $settlement->currency()))]));
        } else {
            $this->addFlash('success', $this->translator->trans('invoice.payment.flash.recorded', $recorded));
        }

        return $this->toList($return);
    }

    /**
     * Removes a payment from the invoice. For a bank payment the movement goes back
     * to the unmatched queue; the confirmation says so.
     */
    #[Route('/invoices/{id}/payments/{paymentId}/cancel', name: 'app_invoice_payment_cancel', requirements: ['id' => Requirement::UUID_V7, 'paymentId' => Requirement::UUID], methods: ['POST'])]
    public function cancel(string $id, string $paymentId, Request $request): RedirectResponse
    {
        $this->denyUnlessStaff();
        $invoice = $this->invoice($id);
        $this->checkToken($request);
        try {
            $payment = $this->payments->get($paymentId);
        } catch (UnknownPayment) {
            throw new NotFoundHttpException();
        }
        $edit = $this->redirectToRoute('app_invoice_edit', ['id' => $invoice->id()]);
        if ($payment->isCancelled()) {
            $this->addFlash('warning', $this->translator->trans('invoice.payment.flash.already_removed'));

            return $edit;
        }
        $lines = array_values(array_filter($this->payments->linesOf($invoice), static fn (InvoicePaymentLine $line): bool => $line->paymentId === $paymentId));
        if ([] === $lines) {
            throw new NotFoundHttpException('The payment pays another invoice.');
        }
        $this->cancelling->cancel($paymentId);
        $this->addFlash('success', $this->translator->trans('invoice.payment.flash.removed', ['amount' => $this->amounts->shown($lines[0]->allocated)]));

        return $edit;
    }

    /**
     * @return Values
     */
    private function prefilled(Invoice $invoice): array
    {
        $remaining = $this->payments->remainingOf($invoice);

        return [
            'receivedOn' => $this->todayOf($invoice)->format('Y-m-d'),
            // Overpaid leaves less than nothing to pay: nothing is filled in.
            'amount' => $this->positive($remaining) ? $this->amounts->plain($remaining) : '',
            'method' => $invoice->paymentMethod()->value,
            'note' => '',
            'settle' => false,
        ];
    }

    /**
     * The difference the payment leaves, written off so the invoice closes exactly:
     * what is still to pay less the amount, below zero when the amount is a few
     * coins more. Never typed: the box only asks whether to close it. RecordPayment
     * checks it again under the lock of the invoice.
     *
     * @param array<string, string> $errors
     */
    private function settlement(Invoice $invoice, Money $amount, array &$errors): ?Money
    {
        $difference = $this->payments->remainingOf($invoice)->minus($amount);
        if ($difference->isZero()) {
            return null;
        }
        $limit = $this->settlementLimit($invoice);
        if (null === $limit || abs($difference->minor()) > $limit) {
            $errors['settle'] = null === $limit
                ? $this->translator->trans('invoice.payment.error.settle_none')
                : $this->translator->trans('invoice.payment.error.settle_too_large', ['limit' => $this->amounts->shown(Money::ofMinor($limit, $invoice->currency()))]);

            return null;
        }

        return $difference;
    }

    /**
     * How large a difference the issuer's country writes off, in minor units of the
     * invoice's currency; null where it writes off none.
     *
     * The one place that asks: the rule is moving into the invoicing country module
     * (InvoicingCountry::settlementLimit), and this follows it there.
     */
    private function settlementLimit(Invoice $invoice): ?int
    {
        return \in_array($invoice->currency(), RecordPayment::SETTLEMENT_CURRENCIES, true) ? RecordPayment::MAX_SETTLEMENT : null;
    }

    /**
     * @param array<string, string> $errors
     */
    private function date(string $typed, Invoice $invoice, array &$errors): ?\DateTimeImmutable
    {
        $date = 1 === preg_match(self::DATE, $typed) ? \DateTimeImmutable::createFromFormat('!Y-m-d', $typed) : false;
        if (false === $date || $date->format('Y-m-d') !== $typed) {
            $errors['receivedOn'] = $this->translator->trans('invoice.payment.error.date');

            return null;
        }
        if ($typed > $this->todayOf($invoice)->format('Y-m-d')) {
            $errors['receivedOn'] = $this->translator->trans('invoice.payment.refused.future_date');

            return null;
        }

        return $date;
    }

    /**
     * @param array<string, string> $errors
     */
    private function amount(string $typed, Currency $currency, string $locale, array &$errors): ?Money
    {
        $decimal = TypedNumber::decimal($typed, $locale);
        if (1 === preg_match(self::AMOUNT, $decimal)) {
            $amount = Money::of($decimal, $currency);
            if ($this->positive($amount)) {
                return $amount;
            }
        }
        $errors['amount'] = $this->translator->trans('' === $decimal || 1 === preg_match(self::AMOUNT, $decimal) || 1 === preg_match(self::NEGATIVE, $decimal) ? 'invoice.payment.refused.not_positive' : 'invoice.payment.error.amount');

        return null;
    }

    /**
     * @return array<string, string>
     */
    private function refusal(PaymentRefused $refused): array
    {
        $field = match ($refused->reason) {
            PaymentRefused::NOT_POSITIVE => 'amount',
            PaymentRefused::FUTURE_DATE => 'receivedOn',
            PaymentRefused::NOTE_TOO_LONG => 'note',
            // Another payment came in meanwhile: the difference is no longer the one ticked.
            PaymentRefused::SETTLEMENT_NOT_ALLOWED => 'settle',
            default => self::FORM,
        };

        return [$field => $this->translator->trans('invoice.payment.refused.'.$refused->reason, ['max' => Payment::MAX_NOTE])];
    }

    /**
     * @param Values                $values
     * @param array<string, string> $errors by field; "form" for one that names none
     */
    private function page(Invoice $invoice, array $values, array $errors, string $token, string $return, int $status): Response
    {
        $payable = $invoice->payable();
        $remaining = $this->payments->remainingOf($invoice);
        $due = $this->positive($remaining) ? $remaining : Money::ofMinor(0, $invoice->currency());
        $methods = [];
        foreach (PaymentMethod::cases() as $method) {
            $methods[$method->value] = $this->translator->trans('invoice.payment.'.$method->value);
        }

        return $this->render('invoicing/pay.html.twig', [
            'invoice' => $invoice,
            'values' => $values,
            'errors' => $errors,
            'token' => $token,
            'return' => $return,
            'cancel' => $this->listUrl($return),
            'methods' => $methods,
            'today' => $this->todayOf($invoice)->format('Y-m-d'),
            'payable' => $this->amounts->shown($payable),
            'remaining' => $this->amounts->shown($due),
            'remainingMinor' => $due->minor(),
            'partly' => $due->minor() !== $payable->minor(),
            'settled' => !$this->positive($remaining),
            'unit' => $this->amounts->unit($invoice->currency()),
            'maxNote' => Payment::MAX_NOTE,
            'limit' => $this->settlementLimit($invoice),
            'settle' => $this->settleBox($invoice, $values, $due, $this->locales->getLocale()),
        ], new Response(status: $status));
    }

    /**
     * Without JavaScript the box shows when the amount as typed leaves a difference it
     * may close, or when it was ticked; the script shows and hides it while typing.
     *
     * @param Values $values
     *
     * @return array{shown: bool, difference: ?string}
     */
    private function settleBox(Invoice $invoice, array $values, Money $due, string $locale): array
    {
        $limit = $this->settlementLimit($invoice);
        $decimal = TypedNumber::decimal($values['amount'], $locale);
        if (null === $limit || 1 !== preg_match(self::AMOUNT, $decimal)) {
            return ['shown' => null !== $limit && $values['settle'], 'difference' => null];
        }
        $difference = $due->minus(Money::of($decimal, $invoice->currency()));
        $within = !$difference->isZero() && abs($difference->minor()) <= $limit;

        return [
            'shown' => $within || $values['settle'],
            'difference' => $within ? $this->amounts->shown(Money::ofMinor(abs($difference->minor()), $difference->currency())) : null,
        ];
    }

    private function positive(Money $amount): bool
    {
        return !$amount->isZero() && !$amount->isNegative();
    }

    private function todayOf(Invoice $invoice): \DateTimeImmutable
    {
        $now = \DateTimeImmutable::createFromInterface($this->clock->now());
        try {
            return $now->setTimezone($this->countries->for($invoice->firm()->address()->countryCode)->timezone());
        } catch (UnsupportedCountry) {
            return $now;
        }
    }

    private function toList(string $return): RedirectResponse
    {
        return $this->redirect($this->listUrl($return));
    }

    /**
     * Only the known parts of the query come back: the value travelled through the browser.
     */
    private function listUrl(string $return): string
    {
        parse_str($return, $query);

        return $this->generateUrl('app_invoices', array_filter(
            array_intersect_key($query, array_flip(InvoiceListController::LIST_QUERY)),
            \is_string(...),
        ));
    }

    private function invoice(string $id): Invoice
    {
        try {
            return Uuid::isValid($id) ? $this->store->get($id) : throw new NotFoundHttpException();
        } catch (UnknownInvoice) {
            throw new NotFoundHttpException();
        }
    }

    private function denyUnlessStaff(): void
    {
        if (!$this->staff->isStaff()) {
            throw new AccessDeniedHttpException('Only staff record payments.');
        }
    }

    private function checkToken(Request $request): void
    {
        if (!$this->isCsrfTokenValid(self::FORM_TOKEN, $request->request->getString('_token'))) {
            throw new AccessDeniedHttpException('Invalid CSRF token.');
        }
    }
}
