<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Party\Infrastructure\Mcp;

use App\Party\Application\PartyNotAllowed;
use App\Party\Application\Query\ContactDetails;
use App\Party\Application\UnknownParty;
use App\Party\Infrastructure\Api\ContactJson;
use App\Shared\Infrastructure\Mcp\ReadTool;
use App\Shared\Infrastructure\Mcp\ToolArguments;
use App\Shared\Infrastructure\Mcp\ToolOutput;
use App\Shared\Infrastructure\Mcp\ToolRefusal;

/**
 * One contact of the address book, as GET /api/v1/contacts/{id} writes it.
 */
final readonly class GetContactTool implements ReadTool
{
    public function __construct(private ContactDetails $details)
    {
    }

    public function name(): string
    {
        return 'foresi_get_contact';
    }

    public function title(): string
    {
        return 'Get a contact';
    }

    public function description(): string
    {
        return 'Returns one contact of the address book by its id, an archived one too: what foresi_search_contacts returns for it, '
            .'with its phone, mobile, website, whether it is an unreliable VAT payer, all its addresses and e-mail addresses, its bank accounts and its contact persons. '
            .'Find the id with foresi_search_contacts, or in the customer of a document.';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => ['id' => ToolArguments::idSchema('The id of the contact, as foresi_search_contacts returns it.')],
            'required' => ['id'],
            'additionalProperties' => false,
        ];
    }

    public function call(array $arguments): ToolOutput
    {
        try {
            $detail = $this->details->of(ToolArguments::text($arguments, 'id') ?? '');
        } catch (PartyNotAllowed) {
            throw ToolRefusal::forbidden('Only the owners and members of the organisation read its contacts.');
        } catch (UnknownParty) {
            throw ToolRefusal::notFound('The organisation has no contact with this id. Find the id with foresi_search_contacts.');
        }

        return new ToolOutput(ContactJson::detail($detail), 'The contact with its addresses, e-mails, bank accounts and contact persons.');
    }
}
