<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Invoicing\Application\Api;

use Foresi\Invoicing\Domain\Invoice;
use Foresi\Invoicing\Domain\RecurringInvoice;
use Foresi\Shared\Contract\Operation\Field;
use Foresi\Shared\Contract\Operation\Operation;

/**
 * A new recurring invoice over the API and MCP, as the form saves it: from
 * scratch, or from an issued invoice as "Make recurring" starts it. The same
 * fields preview a schedule without saving (foresi_preview_recurring_invoice).
 */
#[Operation(
    name: 'recurring-invoices.create',
    method: 'POST',
    path: '/api/v1/recurring-invoices',
    tool: 'foresi_create_recurring_invoice',
    scope: 'invoices:write',
    title: 'Create a recurring invoice',
    description: 'Creates a recurring invoice: a template Foresi issues a new sales invoice from on every date of its schedule, each with the next number of its series. Check the customer, lines, schedule and delivery with the person before you call it, and preview them first with foresi_preview_recurring_invoice, which takes the same fields and saves nothing. '
        .'With from_invoice_id it starts from an issued invoice as "Make recurring" does: its customer, lines, texts, payment and order number, and its due days; any field you send replaces what the invoice gives, so send lines to drop or change some (read them with foresi_preview_recurring_invoice first). Without it, customer_id and lines are needed and the rest takes the defaults of the form for the customer. '
        .'delivery decides what happens to each issued invoice: send e-mails it to the customer, review only issues it and keeps it under "To send" for the person to send themselves. '
        .'The schedule is first_issue_on (today or later; today when left out), repeat (day, week, month or year; month by default) every N units, optionally on the last day of the month, and end: never, on_date with ends_on, or after_count with ends_after invoices. '
        .'Lines are as foresi_create_invoice takes them; the texts may hold the period variables {day}, {month}, {year}, {quarter} and {month/year}, shifted as {month+1}, and {document_number}. '
        .'A refusal lists every problem in details.problems as "parameter: reason". Change it later with foresi_update_recurring_invoice.',
    status: 201,
    response: 'RecurringInvoiceDetail',
)]
final readonly class CreateRecurringInvoiceInput
{
    /**
     * @param list<array<string, mixed>>|null $lines
     */
    public function __construct(
        #[Field('What becomes of each issued invoice: send e-mails it to the customer; review only issues it, for the person to look at and send themselves.', enum: ['send', 'review'])]
        public string $delivery,
        #[Field('The id of an issued invoice to start from, as "Make recurring" does; what else you send replaces what it gives.', format: 'uuid')]
        public ?string $fromInvoiceId = null,
        #[Field('The id of the customer, a contact of the address book; needed without from_invoice_id.', format: 'uuid')]
        public ?string $customerId = null,
        #[Field('Every line of each invoice, as foresi_create_invoice takes them; needed without from_invoice_id, and replacing its lines with it.', items: Field::OBJECTS)]
        public ?array $lines = null,
        #[Field('The name of the template in the list; the description when left out.', maxLength: RecurringInvoice::MAX_NAME_LENGTH)]
        public ?string $name = null,
        #[Field('The first issue date, YYYY-MM-DD, today or later; today when left out.', format: 'date')]
        public ?string $firstIssueOn = null,
        #[Field('The unit the schedule repeats in.', enum: ['day', 'week', 'month', 'year'])]
        public string $repeat = 'month',
        #[Field('Every how many units, 1 to 99.')]
        public int $every = 1,
        #[Field('For month and year: on the last day of the month instead of the day of first_issue_on.')]
        public bool $lastDayOfMonth = false,
        #[Field('When the schedule ends: never, on_date (ends_on) or after_count (ends_after invoices).', enum: ['never', 'on_date', 'after_count'])]
        public string $end = 'never',
        #[Field('With end on_date: the last day an invoice may be issued on, YYYY-MM-DD.', format: 'date')]
        public ?string $endsOn = null,
        #[Field('With end after_count: how many invoices it issues in all, 1 to 999.')]
        public ?int $endsAfter = null,
        #[Field('The tax point of each invoice: its issue date, or the last day of its month.', enum: ['issue_date', 'last_day_of_month'])]
        public string $taxPoint = 'issue_date',
        #[Field('Days from the issue date to the due date, 0 to 365; the invoice\'s, or the customer\'s default, when left out.')]
        public ?int $dueDays = null,
        #[Field('The id of the series to number in; the default series when left out.', format: 'uuid')]
        public ?string $seriesId = null,
        #[Field('How it is paid.', enum: ['bank_transfer', 'cash', 'card'])]
        public ?string $paymentMethod = null,
        #[Field('The IBAN of the firm\'s account to pay to.', maxLength: 34)]
        public ?string $bankAccount = null,
        #[Field('The constant symbol, digits; empty for none.', maxLength: Invoice::MAX_CONSTANT_SYMBOL_LENGTH)]
        public ?string $constantSymbol = null,
        #[Field('The customer\'s order number; empty for none.', maxLength: Invoice::MAX_ORDER_NUMBER_LENGTH)]
        public ?string $orderNumber = null,
        #[Field('What each invoice is for, shown in the lists; may hold period variables.', maxLength: 2000)]
        public ?string $description = null,
        #[Field('The text printed above the lines.', maxLength: 2000)]
        public ?string $textBefore = null,
        #[Field('The text printed below the lines.', maxLength: 2000)]
        public ?string $textAfter = null,
        #[Field('The language of the documents.', enum: ['cs', 'en'])]
        public ?string $language = null,
        #[Field('With delivery send: also send a copy to the firm\'s contact address, or to the template\'s owner.')]
        public bool $copyToMe = true,
        #[Field('With delivery send: also send a copy to the customer\'s copy addresses.')]
        public bool $copyToOthers = true,
    ) {
    }
}
