<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Purchasing\Infrastructure\Mcp;

use Foresi\Purchasing\Application\PurchasingNotAllowed;
use Foresi\Purchasing\Application\Query\ReceivedDocumentBalances;
use Foresi\Purchasing\Application\UnknownReceivedDocument;
use Foresi\Purchasing\Infrastructure\Api\ReceivedDocumentJson;
use Foresi\Shared\Infrastructure\Mcp\ReadTool;
use Foresi\Shared\Infrastructure\Mcp\ToolArguments;
use Foresi\Shared\Infrastructure\Mcp\ToolOutput;
use Foresi\Shared\Infrastructure\Mcp\ToolRefusal;

/**
 * List the payments of a received document, as the REST API writes it.
 */
final readonly class ListReceivedDocumentPaymentsTool implements ReadTool
{
    public function __construct(private ReceivedDocumentBalances $balances)
    {
    }

    public function name(): string
    {
        return 'foresi_list_received_document_payments';
    }

    public function title(): string
    {
        return 'List the payments of a received document';
    }

    public function scope(): string
    {
        return 'received_documents:read';
    }

    public function description(): string
    {
        return 'Returns the payments of one received document, by the day paid: the id, day, amount, method and the bank movement of each. What is paid and what remains in total is in foresi_get_received_document.';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => ['id' => ToolArguments::idSchema('The id of the received document, as foresi_search_received_documents returns it.')],
            'required' => ['id'],
            'additionalProperties' => false,
        ];
    }

    public function call(array $arguments): ToolOutput
    {
        try {
            $paid = $this->balances->of(ToolArguments::text($arguments, 'id') ?? '');
        } catch (PurchasingNotAllowed) {
            throw ToolRefusal::forbidden('Only the owners and members of the organisation read its received documents.');
        } catch (UnknownReceivedDocument $unknown) {
            throw ToolRefusal::notFound($unknown->getMessage());
        }

        $payments = array_map(ReceivedDocumentJson::payment(...), $paid->payments);

        return new ToolOutput(['items' => $payments], \sprintf('%d payment%s.', \count($payments), 1 === \count($payments) ? '' : 's'));
    }
}
