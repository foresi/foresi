<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Invoicing\Infrastructure\Form;

use App\Invoicing\Application\CopyInvoice;
use App\Invoicing\Application\CustomerPicker;
use App\Invoicing\Application\InvoiceDraftValues;
use App\Invoicing\Application\LineInput;
use App\Invoicing\Application\NewInvoiceDefaults;
use App\Invoicing\Application\RecurringInvoices;
use App\Invoicing\Application\UnsupportedCountry;
use App\Invoicing\Domain\Invoice;
use App\Invoicing\Domain\RecurrenceEnd;
use App\Invoicing\Domain\RecurrenceUnit;
use App\Invoicing\Domain\RecurringDelivery;
use App\Invoicing\Domain\RecurringInvoice;
use App\Invoicing\Domain\RecurringInvoiceLine;
use App\Invoicing\Domain\TaxPointRule;
use App\Invoicing\Domain\VatRegime;
use App\Party\Domain\LegalEntity;

/**
 * The form of a recurring template as text: for a new one, for one started from an
 * invoice and for a stored one. What a template shares with an invoice comes from
 * the invoice form's values, so lines, customer and texts read the same on both.
 */
final readonly class RecurringFormValues
{
    public function __construct(
        private InvoiceFormValues $values,
        private NewInvoiceDefaults $defaults,
        private CopyInvoice $copies,
        private CustomerPicker $picker,
        private RecurringInvoices $templates,
    ) {
    }

    /**
     * @param \DateTimeImmutable $today the tenant's today, the first issue it offers
     *
     * @return array<string, mixed>
     *
     * @throws UnsupportedCountry
     */
    public function ofNew(LegalEntity $firm, \DateTimeImmutable $today): array
    {
        $draft = $this->defaults->for($firm);

        return [...$this->values->ofDraft($draft), ...$this->schedule($today, $this->dueDays($draft)), 'name' => '', 'copyOf' => ''];
    }

    /**
     * The template an invoice would make: its customer, lines, texts and payment, as
     * a copy of it has them, and its order number and due days. Nothing is written.
     *
     * @return array<string, mixed>
     *
     * @throws UnsupportedCountry
     */
    public function ofInvoice(Invoice $source, \DateTimeImmutable $today): array
    {
        $draft = $this->copies->valuesFrom($source);
        $values = $this->values->ofCopy($draft, $source->firm(), $source);
        $days = (int) $source->issueDate()->diff($source->dueDate())->format('%r%a');

        return [...$values, ...$this->schedule($today, max(0, min(RecurringInvoice::MAX_DUE_DAYS, $days))), 'orderNumber' => $source->orderNumber(), 'name' => $this->nameFrom($values), 'copyOf' => $source->id()];
    }

    /**
     * @return array<string, mixed>
     */
    public function ofTemplate(RecurringInvoice $template, VatRegime $regime): array
    {
        $firm = $template->firm();
        $recurrence = $template->recurrence();
        $first = $recurrence->firstIssueOn();
        $lines = array_map(static fn (RecurringInvoiceLine $line): LineInput => new LineInput($line->text(), $line->quantity(), $line->unit(), $line->price(), $line->priceType(), $line->treatment(), $line->rateKind(), $line->supplyCode(), $line->discount()), $this->templates->lines($template));
        $draft = new InvoiceDraftValues($firm->id(), $regime, $template->currency(), $template->series()?->id(), '', $first, $first, $first, $template->paymentMethod(), $template->bankAccount(), '', $template->constantSymbol(), $template->textBefore(), $template->textAfter(), $template->language(), $lines, description: $template->description());
        $values = $this->values->withCustomerShown($this->values->ofDraft($draft), $this->picker->option($template->customer(), $firm));

        return [
            ...$values,
            'orderNumber' => $template->orderNumber(),
            'firstIssueOn' => $first->format('Y-m-d'),
            'unit' => $recurrence->unit()->value,
            'every' => (string) $recurrence->every(),
            'onLastDayOfMonth' => $recurrence->onLastDayOfMonth() ? '1' : '',
            'taxPoint' => $template->taxPoint()->value,
            'dueDays' => (string) $template->dueDays(),
            'dueDaysSet' => '1',
            'end' => $recurrence->end()->value,
            'endOn' => $recurrence->endOn()?->format('Y-m-d') ?? '',
            'endAfter' => null === $recurrence->endAfter() ? '' : (string) $recurrence->endAfter(),
            'name' => $template->name(),
            ...$this->sending(RecurringDelivery::Send === $template->delivery(), $template->copyToMe(), $template->copyToOthers()),
            'copyOf' => '',
        ];
    }

    /**
     * @return array<string, string>
     */
    private function schedule(\DateTimeImmutable $today, int $dueDays): array
    {
        return [
            'firstIssueOn' => $today->format('Y-m-d'),
            'unit' => RecurrenceUnit::Month->value,
            'every' => '1',
            'onLastDayOfMonth' => '',
            'taxPoint' => TaxPointRule::IssueDate->value,
            'dueDays' => (string) $dueDays,
            'dueDaysSet' => '',
            'end' => RecurrenceEnd::Never->value,
            'endOn' => '',
            'endAfter' => '',
            ...$this->sending(true, true, true),
        ];
    }

    /**
     * @return array{sendToCustomer: string, copyToMe: string, copyToOthers: string}
     */
    private function sending(bool $customer, bool $me, bool $others): array
    {
        return ['sendToCustomer' => $customer ? '1' : '', 'copyToMe' => $me ? '1' : '', 'copyToOthers' => $others ? '1' : ''];
    }

    /**
     * A template started from an invoice is named after its description, as a
     * template without a name is (RecurringInvoice).
     *
     * @param array<string, mixed> $values
     */
    private function nameFrom(array $values): string
    {
        $description = $values['description'] ?? '';

        return \is_string($description) ? mb_substr(trim(preg_replace('/\s+/u', ' ', $description) ?? ''), 0, RecurringInvoice::MAX_NAME_LENGTH) : '';
    }

    private function dueDays(InvoiceDraftValues $draft): int
    {
        return (int) $draft->issueDate->diff($draft->dueDate)->format('%r%a');
    }
}
