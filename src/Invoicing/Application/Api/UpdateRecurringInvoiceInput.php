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
 * Changes a recurring template over the API and MCP, with the rules of the
 * edit form: a field left out keeps what the template has, and the version
 * read with it guards against a save someone made meanwhile.
 */
#[Operation(
    name: 'recurring-invoices.update',
    method: 'PATCH',
    path: '/api/v1/recurring-invoices/{recurringInvoiceId}',
    tool: 'foresi_update_recurring_invoice',
    scope: 'invoices:write',
    title: 'Change a recurring invoice',
    description: 'Changes a recurring invoice with the rules of the edit form; a field left out keeps what it has. It changes the invoices it issues from now on, never those issued already. '
        .'Send the version foresi_get_recurring_invoice returned: when someone saved it since, it is refused (changed_meanwhile) and you read it again. '
        .'lines replaces every line, in the shape foresi_get_recurring_invoice returns them; leave it out to keep them. The fields are those of foresi_create_recurring_invoice. '
        .'An ended template, or one in the trash, cannot change. A refusal lists every problem in details.problems as "parameter: reason".',
    response: 'RecurringInvoiceDetail',
)]
final readonly class UpdateRecurringInvoiceInput
{
    /**
     * @param list<array<string, mixed>>|null $lines
     */
    public function __construct(
        #[Field('The id of the recurring invoice.', format: 'uuid')]
        public string $recurringInvoiceId,
        #[Field('The version of the recurring invoice as foresi_get_recurring_invoice returned it.')]
        public int $version,
        #[Field('The id of the customer, a contact of the address book.', format: 'uuid')]
        public ?string $customerId = null,
        #[Field('Every line, replacing those it has, as foresi_create_invoice takes them.', items: Field::OBJECTS)]
        public ?array $lines = null,
        #[Field('What becomes of each issued invoice: send e-mails it to the customer; review only issues it, for the person to send themselves.', enum: ['send', 'review'])]
        public ?string $delivery = null,
        #[Field('The name of the template in the list.', maxLength: RecurringInvoice::MAX_NAME_LENGTH)]
        public ?string $name = null,
        #[Field('The first issue date, YYYY-MM-DD; a changed one must be today or later.', format: 'date')]
        public ?string $firstIssueOn = null,
        #[Field('The unit the schedule repeats in.', enum: ['day', 'week', 'month', 'year'])]
        public ?string $repeat = null,
        #[Field('Every how many units, 1 to 99.')]
        public ?int $every = null,
        #[Field('For month and year: on the last day of the month.')]
        public ?bool $lastDayOfMonth = null,
        #[Field('When the schedule ends: never, on_date (ends_on) or after_count (ends_after invoices).', enum: ['never', 'on_date', 'after_count'])]
        public ?string $end = null,
        #[Field('With end on_date: the last day an invoice may be issued on, YYYY-MM-DD.', format: 'date')]
        public ?string $endsOn = null,
        #[Field('With end after_count: how many invoices it issues in all, 1 to 999.')]
        public ?int $endsAfter = null,
        #[Field('The tax point of each invoice: its issue date, or the last day of its month.', enum: ['issue_date', 'last_day_of_month'])]
        public ?string $taxPoint = null,
        #[Field('Days from the issue date to the due date, 0 to 365.')]
        public ?int $dueDays = null,
        #[Field('The id of the series to number in.', format: 'uuid')]
        public ?string $seriesId = null,
        #[Field('How it is paid.', enum: ['bank_transfer', 'cash', 'card'])]
        public ?string $paymentMethod = null,
        #[Field('The IBAN of the firm\'s account to pay to.', maxLength: 34)]
        public ?string $bankAccount = null,
        #[Field('The constant symbol, digits; empty for none.', maxLength: Invoice::MAX_CONSTANT_SYMBOL_LENGTH)]
        public ?string $constantSymbol = null,
        #[Field('The customer\'s order number; empty for none.', maxLength: Invoice::MAX_ORDER_NUMBER_LENGTH)]
        public ?string $orderNumber = null,
        #[Field('What each invoice is for; may hold period variables.', maxLength: 2000)]
        public ?string $description = null,
        #[Field('The text printed above the lines.', maxLength: 2000)]
        public ?string $textBefore = null,
        #[Field('The text printed below the lines.', maxLength: 2000)]
        public ?string $textAfter = null,
        #[Field('The language of the documents.', enum: ['cs', 'en'])]
        public ?string $language = null,
        #[Field('With delivery send: also send a copy to the firm.')]
        public ?bool $copyToMe = null,
        #[Field('With delivery send: also send a copy to the customer\'s copy addresses.')]
        public ?bool $copyToOthers = null,
    ) {
    }
}
