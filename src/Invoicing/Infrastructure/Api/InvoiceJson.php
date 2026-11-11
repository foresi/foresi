<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Invoicing\Infrastructure\Api;

use App\Invoicing\Application\InvoicePaymentLine;
use App\Invoicing\Application\PaymentState;
use App\Invoicing\Application\Query\InvoiceDetail;
use App\Invoicing\Application\Query\InvoiceListItem;
use App\Invoicing\Domain\Invoice;
use App\Invoicing\Domain\InvoiceLine;
use App\Party\Domain\Address;
use App\Shared\Infrastructure\Api\JsonValues;

/**
 * Sales documents as the REST API and the MCP tools write them. The names are the
 * ones the document holds. The history stays on the screen.
 */
final class InvoiceJson
{
    /**
     * @return array<string, mixed>
     */
    public static function item(InvoiceListItem $item): array
    {
        $row = $item->row;

        return [
            'id' => $row->id,
            'number' => $row->number,
            'kind' => $row->kind->value,
            'firm' => ['id' => $item->firmId, 'name' => $row->firmName],
            'customer' => ['id' => $item->customerId, 'name' => $row->customerName],
            'issue_date' => JsonValues::date($row->issueDate),
            'due_date' => JsonValues::date($row->dueDate),
            'tax_point_date' => JsonValues::date($item->taxPointDate),
            'total' => JsonValues::money($row->payable),
            'paid' => JsonValues::money($row->paid),
            'remaining' => JsonValues::money($row->remaining()),
            'payment_state' => $row->payment->value,
            'overdue' => $row->overdue,
            'delivered' => $row->delivered,
            'trashed' => $row->trashed,
            'created_at' => JsonValues::timestamp($item->createdAt),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function detail(InvoiceDetail $detail): array
    {
        $invoice = $detail->invoice;
        $customer = $invoice->customer();

        return [
            'id' => $invoice->id(),
            'number' => $invoice->number(),
            'kind' => $invoice->kind()->value,
            'firm' => ['id' => $invoice->firm()->id(), 'name' => $invoice->issuer()->name],
            'customer' => [
                'id' => $invoice->customerParty()->id(),
                'name' => $customer->name,
                'registration_number' => $customer->registrationNumber,
                'vat_id' => $customer->vatId,
                'address' => self::address($customer->address),
            ],
            'issue_date' => JsonValues::date($invoice->issueDate()),
            'due_date' => JsonValues::date($invoice->dueDate()),
            'tax_point_date' => JsonValues::date($invoice->taxPointDate()),
            'currency' => $invoice->currency()->value,
            'variable_symbol' => $invoice->variableSymbol(),
            'order_number' => $invoice->orderNumber(),
            'description' => $invoice->description(),
            'payment_method' => $invoice->paymentMethod()->value,
            'net' => JsonValues::money($invoice->net()),
            'vat' => JsonValues::money($invoice->vat()),
            'total' => JsonValues::money($invoice->payable()),
            'payment_state' => PaymentState::ofDocument($invoice->kind(), $invoice->payable(), $detail->paid, $detail->corrections)->value,
            'delivered' => $invoice->isDelivered(),
            'trashed' => $invoice->isTrashed(),
            'created_at' => JsonValues::timestamp($invoice->createdAt()),
            'corrected_invoice_id' => $invoice->correctedInvoiceId(),
            'lines' => array_map(self::line(...), $detail->lines),
            'payments' => array_map(self::payment(...), $detail->payments),
            'corrections' => array_map(self::correction(...), $detail->corrective),
            'balances' => [
                'paid' => JsonValues::money($detail->paid),
                'corrections' => JsonValues::money($detail->corrections),
                'remaining' => JsonValues::money($detail->remaining),
                'to_refund' => JsonValues::money($detail->toRefund),
            ],
        ];
    }

    /**
     * A line, in the order of the document.
     *
     * @return array<string, mixed>
     */
    private static function line(InvoiceLine $line): array
    {
        return [
            'description' => $line->text(),
            'quantity' => $line->quantity()->toDecimal(),
            'unit' => $line->unit(),
            'unit_price' => JsonValues::money($line->price()),
            'price_type' => $line->priceType()->value,
            'discount_percent' => $line->discount()->decimal(),
            'vat_treatment' => $line->treatment()->value,
            'vat_rate_kind' => $line->rateKind()?->value,
            'vat_rate_percent' => $line->rate()?->decimal(),
            'net' => JsonValues::money($line->net()),
            'vat' => JsonValues::money($line->vat()),
            'gross' => JsonValues::money($line->gross()),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function payment(InvoicePaymentLine $payment): array
    {
        return [
            'payment_id' => $payment->paymentId,
            'received_on' => JsonValues::date($payment->receivedOn),
            'allocated' => JsonValues::money($payment->allocated),
            'settlement' => JsonValues::money($payment->settlement),
            'method' => $payment->method->value,
            'source' => $payment->source->value,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function correction(Invoice $correction): array
    {
        return [
            'id' => $correction->id(),
            'number' => $correction->number(),
            'issue_date' => JsonValues::date($correction->issueDate()),
            'total' => JsonValues::money($correction->payable()),
        ];
    }

    /**
     * @return array{street: string, city: string, postal_code: string, country_code: string}
     */
    private static function address(Address $address): array
    {
        return ['street' => $address->street, 'city' => $address->city, 'postal_code' => $address->postalCode, 'country_code' => $address->countryCode];
    }
}
