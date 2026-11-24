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
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Get a received document, as the REST API writes it.
 */
final readonly class GetReceivedDocumentTool implements ReadTool
{
    public function __construct(private ReceivedDocumentBalances $balances, private InstanceFirm $firm, private TranslatorInterface $translator)
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
        return 'Returns one received document by its id, a draft or one in the trash too: what foresi_search_received_documents returns for it, with the supplier as the document names it, the VAT recap per rate, the lines (what it was for, split by rate: the stored ones, or one implicit line per rate of the recap), the exchange rate, the deduction period and its payments; and where it came from (source) and, for a draft made from a file, its reading: the state, the sources, each value found with its source and whether the draft took it (applied) or left it as a suggestion, the warnings, and a failure code; a reading still in state reading 10 minutes after it was asked for is stuck and can be read again with foresi_read_received_document_again. A matched supplier is the field supplier_name. Find the id with foresi_search_received_documents.';
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

        return new ToolOutput(ReceivedDocumentJson::detail($paid, $this->firm->get(), $this->translator), 'The received document with its VAT recap, lines and payments.');
    }
}
