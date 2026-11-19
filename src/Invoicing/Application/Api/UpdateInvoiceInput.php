<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Invoicing\Application\Api;

use Foresi\Invoicing\Domain\Invoice;
use Foresi\Shared\Application\Api\Field;
use Foresi\Shared\Application\Api\Operation;

/**
 * Changes an issued invoice over the API and MCP, with the rules of the edit
 * form: a field left out keeps what the invoice has.
 */
#[Operation(
    name: 'invoices.update',
    method: 'PATCH',
    path: '/api/v1/invoices/{invoiceId}',
    tool: 'foresi_update_invoice',
    scope: 'invoices:write',
    title: 'Change an invoice',
    description: 'Changes an issued sales invoice or advance invoice with the rules of the edit form; a field left out keeps what the invoice has. '
        .'lines replaces every line, in the format of foresi_create_invoice; leave it out to keep them. '
        .'series_id or number renumber it, which is refused once it was delivered, sent by e-mail or queued to be sent (number_locked, number_locked_sent, number_locked_sending). '
        .'A changed customer is copied onto the document again. A document in the trash, a tax document on payment and the firm cannot change. '
        .'A refusal lists every problem in details.problems as "parameter: reason". Read the invoice with foresi_get_invoice first.',
    response: 'InvoiceDetail',
)]
final readonly class UpdateInvoiceInput
{
    /**
     * @param list<array<string, mixed>>|null $lines
     */
    public function __construct(
        #[Field('The id of the invoice.', format: 'uuid')]
        public string $invoiceId,
        #[Field('The id of the customer, a contact of the address book.', format: 'uuid')]
        public ?string $customerId = null,
        #[Field('Every line of the invoice, replacing those it has, as foresi_create_invoice takes them.', items: Field::OBJECTS)]
        public ?array $lines = null,
        #[Field('The id of another series of the same kind, to renumber it in.', format: 'uuid')]
        public ?string $seriesId = null,
        #[Field('Another number, to renumber it; it must fit the format of the series and be free.', maxLength: Invoice::MAX_NUMBER_LENGTH)]
        public ?string $number = null,
        #[Field('The issue date, YYYY-MM-DD.', format: 'date')]
        public ?string $issueDate = null,
        #[Field('The tax point, YYYY-MM-DD.', format: 'date')]
        public ?string $taxPointDate = null,
        #[Field('The due date, YYYY-MM-DD.', format: 'date')]
        public ?string $dueDate = null,
        #[Field('How it is paid.', enum: ['bank_transfer', 'cash', 'card'])]
        public ?string $paymentMethod = null,
        #[Field('The IBAN of the firm\'s account to pay to.', maxLength: 34)]
        public ?string $bankAccount = null,
        #[Field('The variable symbol, up to 10 digits; empty for none.', maxLength: 10)]
        public ?string $variableSymbol = null,
        #[Field('The constant symbol, digits; empty for none.', maxLength: Invoice::MAX_CONSTANT_SYMBOL_LENGTH)]
        public ?string $constantSymbol = null,
        #[Field('The customer\'s order number; empty for none.', maxLength: Invoice::MAX_ORDER_NUMBER_LENGTH)]
        public ?string $orderNumber = null,
        #[Field('What the invoice is for, shown in the lists.', maxLength: 2000)]
        public ?string $description = null,
        #[Field('The text printed above the lines.', maxLength: 2000)]
        public ?string $textBefore = null,
        #[Field('The text printed below the lines.', maxLength: 2000)]
        public ?string $textAfter = null,
        #[Field('The language of the document.', enum: ['cs', 'en'])]
        public ?string $language = null,
    ) {
    }
}
