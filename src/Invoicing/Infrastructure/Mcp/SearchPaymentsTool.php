<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Invoicing\Infrastructure\Mcp;

use App\Invoicing\Application\Query\PaymentList;
use App\Invoicing\Application\Query\PaymentsNotAllowed;
use App\Invoicing\Domain\PaymentDirection;
use App\Invoicing\Infrastructure\Api\PaymentJson;
use App\Invoicing\Infrastructure\Api\PaymentListQuery;
use App\Shared\Infrastructure\Mcp\ReadTool;
use App\Shared\Infrastructure\Mcp\ToolArguments;
use App\Shared\Infrastructure\Mcp\ToolOutput;
use App\Shared\Infrastructure\Mcp\ToolRefusal;

/**
 * The payments of the organisation's invoices, narrowed and paged as GET
 * /api/v1/payments does it, each as the API writes it.
 */
final readonly class SearchPaymentsTool implements ReadTool
{
    public function __construct(private PaymentList $list, private ToolArguments $arguments)
    {
    }

    public function name(): string
    {
        return 'foresi_search_payments';
    }

    public function title(): string
    {
        return 'Search payments';
    }

    public function description(): string
    {
        return 'Searches the payments of the organisation\'s sales documents, received money and refunds, newest first. Cancelled payments are left out unless include_cancelled is true. '
            .'Every argument narrows the search; with none, it returns the newest payments. '
            .'Returns {items, next_cursor}: each payment has its id, firm, day, amount, direction, method, source, note, the bank movement it came from, when it was cancelled, and the documents it is allocated to with the amounts. '
            .'Money is {amount_minor, currency}, in hundredths of the currency; amounts are positive both ways, the direction tells which way the money went. '
            .'When next_cursor is not null, more payments match: call again with the same arguments and next_cursor as cursor. '
            .'Use foresi_search_invoices for an invoice_id and foresi_list_firms for a firm_id.';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'firm_id' => ToolArguments::idSchema('Only payments of this firm of the organisation.'),
                'invoice_id' => ToolArguments::idSchema('Only payments allocated to this document, paying or refunding it.'),
                'direction' => ToolArguments::choiceSchema(array_column(PaymentDirection::cases(), 'value'), 'Only money received (incoming) or paid back (refund).'),
                'received_from' => ToolArguments::dateSchema('Only payments received on this day or later.'),
                'received_to' => ToolArguments::dateSchema('Only payments received on this day or earlier.'),
                'include_cancelled' => ['type' => 'boolean', 'default' => false, 'description' => 'True returns the cancelled payments too.'],
            ] + ToolArguments::pagingSchema(),
            'additionalProperties' => false,
        ];
    }

    public function call(array $arguments): ToolOutput
    {
        $query = new PaymentListQuery(
            ToolArguments::text($arguments, 'firm_id'),
            ToolArguments::text($arguments, 'invoice_id'),
            ToolArguments::text($arguments, 'direction'),
            ToolArguments::text($arguments, 'received_from'),
            ToolArguments::text($arguments, 'received_to'),
            ToolArguments::flag($arguments, 'include_cancelled'),
            ToolArguments::limit($arguments),
            ToolArguments::text($arguments, 'cursor'),
        );
        $this->arguments->validate($query);
        $afterId = ToolArguments::afterId($query->cursor);
        try {
            $slice = $this->list->slice($query->filter(), $afterId, $query->limit);
        } catch (PaymentsNotAllowed) {
            throw ToolRefusal::forbidden('Only the owners and members of the organisation read its payments.');
        }

        return ToolArguments::page(array_map(PaymentJson::row(...), $slice->rows), $slice->hasMore ? $slice->lastId() : null, 'payment', 'payments');
    }
}
