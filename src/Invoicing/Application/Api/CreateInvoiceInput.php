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
 * A new invoice or advance invoice over the API and MCP. Foresi has no drafts:
 * saving issues it, as the form does, with the same defaults, the same rules
 * and the next number of the series' counter.
 */
#[Operation(
    name: 'invoices.create',
    method: 'POST',
    path: '/api/v1/invoices',
    tool: 'foresi_create_invoice',
    scope: 'invoices:write',
    title: 'Issue an invoice',
    description: 'Issues a sales invoice (or an advance invoice) of the firm to a customer: Foresi has no drafts, so it gets its number at once and counts in the VAT reports. Check the lines and the customer with the person before you call it. '
        .'The customer must be a contact with a billing address: find it with foresi_search_contacts, or create it with foresi_create_contact. '
        .'Without series_id the default series of the kind is used, and without number the next number of its counter; a typed number must fit the series and be free. '
        .'Left out, the dates, payment method, bank account, symbols, texts and language take the defaults the invoice form offers for the customer; the variable symbol then follows the number. '
        .'A line is {text, unit_price, quantity, unit, price_type, vat, discount}; prices are decimals in the currency, such as "1500.00"; vat is base, reduced, second_reduced, reverse_charge, exempt or none (a firm that is no VAT payer uses none). '
        .'A refusal lists every problem in details.problems as "parameter: reason". Change it later with foresi_update_invoice.',
    status: 201,
    response: 'InvoiceDetail',
)]
final readonly class CreateInvoiceInput
{
    /**
     * @param list<array<string, mixed>> $lines
     */
    public function __construct(
        #[Field('The id of the customer, a contact of the address book.', format: 'uuid')]
        public string $customerId,
        #[Field('The lines, at least one: objects of text, unit_price (a decimal such as "1500.00"), and optionally quantity (default "1"), unit, price_type (without_vat by default, with_vat or base_only), vat (base, reduced, second_reduced, reverse_charge, exempt or none; the default of the firm and customer when left out) and discount (a percentage such as "10").', items: Field::OBJECTS)]
        public array $lines,
        #[Field('The id of the firm that issues it; the firm of the instance when left out.', format: 'uuid')]
        public ?string $firmId = null,
        #[Field('What it is: invoice, or advance (an advance invoice, a request for payment that is no tax document).', enum: ['invoice', 'advance'])]
        public string $kind = 'invoice',
        #[Field('The id of the series; the default series of the kind when left out.', format: 'uuid')]
        public ?string $seriesId = null,
        #[Field('The number, when it must not be the next one of the series, such as when continuing the numbering of another system; it must fit the format of the series and be free.', maxLength: Invoice::MAX_NUMBER_LENGTH)]
        public ?string $number = null,
        #[Field('The issue date, YYYY-MM-DD; today when left out.', format: 'date')]
        public ?string $issueDate = null,
        #[Field('The tax point, YYYY-MM-DD; the issue date when left out. An advance invoice has none.', format: 'date')]
        public ?string $taxPointDate = null,
        #[Field('The due date, YYYY-MM-DD; the issue date plus the due days of the customer when left out.', format: 'date')]
        public ?string $dueDate = null,
        #[Field('The currency, ISO 4217; the currency of the firm when left out.', maxLength: 3)]
        public ?string $currency = null,
        #[Field('How it is paid; the default of the customer when left out.', enum: ['bank_transfer', 'cash', 'card'])]
        public ?string $paymentMethod = null,
        #[Field('The IBAN of the firm\'s account to pay to; the primary account of the firm when left out.', maxLength: 34)]
        public ?string $bankAccount = null,
        #[Field('The variable symbol, up to 10 digits; derived from the number when left out.', maxLength: 10)]
        public ?string $variableSymbol = null,
        #[Field('The constant symbol, digits; the firm\'s default when left out.', maxLength: Invoice::MAX_CONSTANT_SYMBOL_LENGTH)]
        public ?string $constantSymbol = null,
        #[Field('The customer\'s order number; empty for none.', maxLength: Invoice::MAX_ORDER_NUMBER_LENGTH)]
        public string $orderNumber = '',
        #[Field('What the invoice is for, shown in the lists; the text of the first line when left out.', maxLength: 2000)]
        public ?string $description = null,
        #[Field('The text printed above the lines; the firm\'s default when left out.', maxLength: 2000)]
        public ?string $textBefore = null,
        #[Field('The text printed below the lines, such as a note; the firm\'s default when left out.', maxLength: 2000)]
        public ?string $textAfter = null,
        #[Field('The language of the document; the customer\'s when left out.', enum: ['cs', 'en'])]
        public ?string $language = null,
    ) {
    }
}
