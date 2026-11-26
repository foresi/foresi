<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Party\Infrastructure\Mcp;

use Foresi\Party\Application\PartyNotAllowed;
use Foresi\Party\Application\Query\ContactDetails;
use Foresi\Party\Application\UnknownParty;
use Foresi\Party\Contract\Field\ContactExtension;
use Foresi\Party\Infrastructure\Api\ContactJson;
use Foresi\Shared\Application\Field\CustomFieldPayload;
use Foresi\Shared\Contract\Mcp\ReadTool;
use Foresi\Shared\Contract\Mcp\ToolOutput;
use Foresi\Shared\Contract\Mcp\ToolRefusal;
use Foresi\Shared\Contract\Mcp\ToolSchema;

/**
 * One contact of the address book, as GET /api/v1/contacts/{id} writes it.
 */
final readonly class GetContactTool implements ReadTool
{
    public function __construct(private ContactDetails $details, private CustomFieldPayload $customFields)
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

    public function scope(): string
    {
        return 'contacts:read';
    }

    public function description(): string
    {
        return 'Returns one contact of the address book by its id, an archived one too: what foresi_search_contacts returns for it, '
            .'with its phone, mobile, website, whether it is an unreliable VAT payer, all its addresses and e-mail addresses, its bank accounts, its contact persons, and its custom_fields by key (see foresi_list_fields). '
            .'Find the id with foresi_search_contacts, or in the customer of a document.';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => ['id' => ToolSchema::idSchema('The id of the contact, as foresi_search_contacts returns it.')],
            'required' => ['id'],
            'additionalProperties' => false,
        ];
    }

    public function call(array $arguments): ToolOutput
    {
        try {
            $detail = $this->details->of(ToolSchema::text($arguments, 'id') ?? '');
        } catch (PartyNotAllowed) {
            throw ToolRefusal::forbidden('Only the owners and members of the organisation read its contacts.');
        } catch (UnknownParty) {
            throw ToolRefusal::notFound('The organisation has no contact with this id. Find the id with foresi_search_contacts.');
        }

        return new ToolOutput($this->customFields->attachOne(ContactExtension::RECORD, ContactJson::detail($detail)), 'The contact with its addresses, e-mails, bank accounts and contact persons.');
    }
}
