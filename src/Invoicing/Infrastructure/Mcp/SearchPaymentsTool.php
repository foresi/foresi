<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Invoicing\Infrastructure\Mcp;

use Foresi\Invoicing\Application\Query\PaymentList;
use Foresi\Invoicing\Application\Query\PaymentRow;
use Foresi\Invoicing\Application\Query\PaymentsNotAllowed;
use Foresi\Invoicing\Domain\PaymentDirection;
use Foresi\Invoicing\Infrastructure\Api\PaymentJson;
use Foresi\Invoicing\Infrastructure\Api\PaymentListQuery;
use Foresi\Party\Application\InstanceFirm;
use Foresi\Shared\Contract\Mcp\ReadTool;
use Foresi\Shared\Contract\Mcp\ToolOutput;
use Foresi\Shared\Contract\Mcp\ToolRefusal;
use Foresi\Shared\Contract\Mcp\ToolSchema;
use Foresi\Shared\Infrastructure\Mcp\ToolArguments;

/**
 * The payments of the organisation's invoices, narrowed and paged as GET
 * /api/v1/payments does it, each as the API writes it.
 */
final readonly class SearchPaymentsTool implements ReadTool
{
    public function __construct(private PaymentList $list, private ToolArguments $arguments, private InstanceFirm $instanceFirm)
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

    public function scope(): string
    {
        return 'payments:read';
    }

    public function description(): string
    {
        return 'Searches the payments of the organisation\'s sales documents, received money and refunds, newest first. Cancelled payments are left out unless include_cancelled is true. '
            .'Every argument narrows the search; with none, it returns the newest payments. '
            .'Returns {items, next_cursor}: each payment has its id, firm, day, amount, direction, method, source, note, the bank movement it came from, when it was cancelled, and the documents it is allocated to with the amounts. '
            .'Money is {amount_minor, currency, minor_units}: amount_minor in minor units, minor_units the decimals of the currency; amounts are positive both ways, the direction tells which way the money went. '
            .'When next_cursor is not null, more payments match: call again with the same arguments and next_cursor as cursor. '
            .'Use foresi_search_invoices for an invoice_id.';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'invoice_id' => ToolSchema::idSchema('Only payments allocated to this document, paying or refunding it.'),
                'direction' => ToolSchema::choiceSchema(array_column(PaymentDirection::cases(), 'value'), 'Only money received (incoming) or paid back (refund).'),
                'received_from' => ToolSchema::dateSchema('Only payments received on this day or later.'),
                'received_to' => ToolSchema::dateSchema('Only payments received on this day or earlier.'),
                'include_cancelled' => ['type' => 'boolean', 'default' => false, 'description' => 'True returns the cancelled payments too.'],
            ] + ToolSchema::pagingSchema(),
            'additionalProperties' => false,
        ];
    }

    public function call(array $arguments): ToolOutput
    {
        $query = new PaymentListQuery(
            ToolSchema::text($arguments, 'invoice_id'),
            ToolSchema::text($arguments, 'direction'),
            ToolSchema::text($arguments, 'received_from'),
            ToolSchema::text($arguments, 'received_to'),
            ToolSchema::flag($arguments, 'include_cancelled'),
            ToolSchema::limit($arguments),
            ToolSchema::text($arguments, 'cursor'),
        );
        $this->arguments->validate($query);
        $afterId = ToolArguments::afterId($query->cursor);
        try {
            $slice = $this->list->slice($query->filter(), $afterId, $query->limit);
        } catch (PaymentsNotAllowed) {
            throw ToolRefusal::forbidden('Only the owners and members of the organisation read its payments.');
        }

        $firm = $this->instanceFirm->find();

        return ToolArguments::page($firm instanceof \Foresi\Party\Domain\LegalEntity ? array_map(static fn (PaymentRow $row): array => PaymentJson::row($row, $firm), $slice->rows) : [], $slice->hasMore ? $slice->lastId() : null, 'payment', 'payments');
    }
}
