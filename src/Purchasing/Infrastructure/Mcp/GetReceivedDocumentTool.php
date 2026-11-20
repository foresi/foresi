<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Purchasing\Infrastructure\Mcp;

use Foresi\Party\Application\InstanceFirm;
use Foresi\Purchasing\Application\PurchasingNotAllowed;
use Foresi\Purchasing\Application\Query\ReceivedDocumentBalances;
use Foresi\Purchasing\Application\UnknownReceivedDocument;
use Foresi\Purchasing\Infrastructure\Api\ReceivedDocumentJson;
use Foresi\Shared\Infrastructure\Mcp\ReadTool;
use Foresi\Shared\Infrastructure\Mcp\ToolArguments;
use Foresi\Shared\Infrastructure\Mcp\ToolOutput;
use Foresi\Shared\Infrastructure\Mcp\ToolRefusal;

/**
 * Get a received document, as the REST API writes it.
 */
final readonly class GetReceivedDocumentTool implements ReadTool
{
    public function __construct(private ReceivedDocumentBalances $balances, private InstanceFirm $firm)
    {
    }

    public function name(): string
    {
        return 'foresi_get_received_document';
    }

    public function title(): string
    {
        return 'Get a received document';
    }

    public function scope(): string
    {
        return 'received_documents:read';
    }

    public function description(): string
    {
        return 'Returns one received document by its id, a draft or one in the trash too: what foresi_search_received_documents returns for it, with the supplier as the document names it, the VAT recap per rate, the exchange rate, the deduction period and its payments. Find the id with foresi_search_received_documents.';
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

        return new ToolOutput(ReceivedDocumentJson::detail($paid, $this->firm->get()), 'The received document with its VAT recap and payments.');
    }
}
