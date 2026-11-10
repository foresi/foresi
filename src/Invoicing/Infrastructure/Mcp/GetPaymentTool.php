<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Invoicing\Infrastructure\Mcp;

use App\Invoicing\Application\Query\PaymentDetails;
use App\Invoicing\Application\Query\PaymentsNotAllowed;
use App\Invoicing\Application\UnknownPayment;
use App\Invoicing\Infrastructure\Api\PaymentJson;
use App\Shared\Infrastructure\Mcp\ReadTool;
use App\Shared\Infrastructure\Mcp\ToolArguments;
use App\Shared\Infrastructure\Mcp\ToolOutput;
use App\Shared\Infrastructure\Mcp\ToolRefusal;

/**
 * One payment of the organisation, as GET /api/v1/payments/{id} writes it.
 */
final readonly class GetPaymentTool implements ReadTool
{
    public function __construct(private PaymentDetails $details)
    {
    }

    public function name(): string
    {
        return 'foresi_get_payment';
    }

    public function title(): string
    {
        return 'Get a payment';
    }

    public function description(): string
    {
        return 'Returns one payment of the organisation by its id, a cancelled one too: its firm, day, amount, direction, method, source, note, the bank movement it came from, when it was cancelled, and the documents it is allocated to with the amounts. '
            .'Money is {amount_minor, currency}, in hundredths of the currency. '
            .'Find the id with foresi_search_payments, or in the payments of foresi_get_invoice.';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => ['id' => ToolArguments::idSchema('The id of the payment, as foresi_search_payments returns it.')],
            'required' => ['id'],
            'additionalProperties' => false,
        ];
    }

    public function call(array $arguments): ToolOutput
    {
        try {
            $payment = $this->details->of(ToolArguments::text($arguments, 'id') ?? '');
        } catch (PaymentsNotAllowed) {
            throw ToolRefusal::forbidden('Only the owners and members of the organisation read its payments.');
        } catch (UnknownPayment) {
            throw ToolRefusal::notFound('The organisation has no payment with this id. Find the id with foresi_search_payments.');
        }

        return new ToolOutput(PaymentJson::row($payment), 'The payment with the documents it is allocated to.');
    }
}
