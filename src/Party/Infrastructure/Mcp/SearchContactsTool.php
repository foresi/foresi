<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Party\Infrastructure\Mcp;

use App\Party\Application\PartyNotAllowed;
use App\Party\Application\Query\ContactList;
use App\Party\Domain\PartyRole;
use App\Party\Infrastructure\Api\ContactJson;
use App\Party\Infrastructure\Api\ContactListQuery;
use App\Shared\Infrastructure\Mcp\ReadTool;
use App\Shared\Infrastructure\Mcp\ToolArguments;
use App\Shared\Infrastructure\Mcp\ToolOutput;
use App\Shared\Infrastructure\Mcp\ToolRefusal;

/**
 * The address book of the organisation, narrowed and paged as GET
 * /api/v1/contacts does it, each contact as the API writes it.
 */
final readonly class SearchContactsTool implements ReadTool
{
    public function __construct(private ContactList $list, private ToolArguments $arguments)
    {
    }

    public function name(): string
    {
        return 'foresi_search_contacts';
    }

    public function title(): string
    {
        return 'Search contacts';
    }

    public function description(): string
    {
        return 'Searches the address book of the organisation (customers, suppliers, partners and authorities, companies and persons), newest first. Archived contacts are left out unless archived is true. '
            .'Every argument narrows the search; with none, it returns the newest contacts. '
            .'Returns {items, next_cursor}: each contact has its id, kind, name, roles, registration country and number, VAT id, general e-mail, billing address and whether it is archived. '
            .'When next_cursor is not null, more contacts match: call again with the same arguments and next_cursor as cursor. '
            .'Use foresi_get_contact for the addresses, e-mails, bank accounts and contact persons of one contact; its id is the customer_id of foresi_search_invoices.';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'query' => ['type' => 'string', 'description' => 'Text looked for in the name, the registration number, the VAT id and the e-mail addresses.'],
                'role' => ToolArguments::choiceSchema(array_column(PartyRole::cases(), 'value'), 'Only contacts with this role.'),
                'archived' => ['type' => 'boolean', 'default' => false, 'description' => 'True returns only the archived contacts, false only those that are not.'],
                'registration_number' => ['type' => 'string', 'description' => 'Only the contact with this company registration number; spaces and punctuation are ignored.'],
                'vat_id' => ['type' => 'string', 'description' => 'Only the contact with this VAT id, such as CZ12345678; spaces and case are ignored.'],
            ] + ToolArguments::pagingSchema(),
            'additionalProperties' => false,
        ];
    }

    public function call(array $arguments): ToolOutput
    {
        $query = new ContactListQuery(
            ToolArguments::text($arguments, 'query') ?? '',
            ToolArguments::text($arguments, 'role'),
            ToolArguments::flag($arguments, 'archived'),
            ToolArguments::text($arguments, 'registration_number'),
            ToolArguments::text($arguments, 'vat_id'),
            ToolArguments::limit($arguments),
            ToolArguments::text($arguments, 'cursor'),
        );
        $this->arguments->validate($query);
        $afterId = ToolArguments::afterId($query->cursor);
        try {
            $slice = $this->list->slice($query->filter(), $afterId, $query->limit);
        } catch (PartyNotAllowed) {
            throw ToolRefusal::forbidden('Only the owners and members of the organisation read its contacts.');
        }

        return ToolArguments::page(array_map(ContactJson::row(...), $slice->rows), $slice->hasMore ? $slice->lastId() : null, 'contact', 'contacts');
    }
}
