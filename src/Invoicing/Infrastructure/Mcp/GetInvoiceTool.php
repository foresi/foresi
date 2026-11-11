<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Invoicing\Infrastructure\Mcp;

use App\Invoicing\Application\InvoicingNotAllowed;
use App\Invoicing\Application\Query\InvoiceDetails;
use App\Invoicing\Application\UnknownInvoice;
use App\Invoicing\Infrastructure\Api\InvoiceJson;
use App\Shared\Infrastructure\Mcp\ReadTool;
use App\Shared\Infrastructure\Mcp\ToolArguments;
use App\Shared\Infrastructure\Mcp\ToolOutput;
use App\Shared\Infrastructure\Mcp\ToolRefusal;

/**
 * One sales document of the organisation, as GET /api/v1/invoices/{id} writes it.
 */
final readonly class GetInvoiceTool implements ReadTool
{
    public function __construct(private InvoiceDetails $details)
    {
    }

    public function name(): string
    {
        return 'foresi_get_invoice';
    }

    public function title(): string
    {
        return 'Get an invoice';
    }

    public function description(): string
    {
        return 'Returns one sales document of the organisation by its id, in the trash too: '
            .'its number, kind, firm, the customer as the document names it, the dates, currency, variable symbol, order number and description, the net, VAT and total, the payment state, '
            .'its lines, the payments allocated to it, its correction documents and its balances (paid, corrections, remaining, to refund). '
            .'Money is {amount_minor, currency}, in hundredths of the currency. '
            .'Find the id with foresi_search_invoices.';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => ['id' => ToolArguments::idSchema('The id of the document, as foresi_search_invoices returns it.')],
            'required' => ['id'],
            'additionalProperties' => false,
        ];
    }

    public function call(array $arguments): ToolOutput
    {
        try {
            $detail = $this->details->of(ToolArguments::text($arguments, 'id') ?? '');
        } catch (InvoicingNotAllowed) {
            throw ToolRefusal::forbidden('Only the owners and members of the organisation read its invoices.');
        } catch (UnknownInvoice) {
            throw ToolRefusal::notFound('The organisation has no document with this id. Find the id with foresi_search_invoices.');
        }

        return new ToolOutput(InvoiceJson::detail($detail), 'The document with its lines, payments, corrections and balances.');
    }
}
