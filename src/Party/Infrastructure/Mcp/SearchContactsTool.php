<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Party\Infrastructure\Mcp;

use Foresi\Party\Application\PartyNotAllowed;
use Foresi\Party\Application\Query\ContactList;
use Foresi\Party\Application\View\ContactLists;
use Foresi\Party\Domain\PartyRole;
use Foresi\Party\Infrastructure\Api\ContactJson;
use Foresi\Party\Infrastructure\Api\ContactListQuery;
use Foresi\Shared\Application\Api\ScopeGuard;
use Foresi\Shared\Application\View\AppliedView;
use Foresi\Shared\Application\View\SavedViewRefused;
use Foresi\Shared\Application\View\SavedViews;
use Foresi\Shared\Contract\Mcp\ReadTool;
use Foresi\Shared\Contract\Mcp\ToolOutput;
use Foresi\Shared\Contract\Mcp\ToolRefusal;
use Foresi\Shared\Contract\Mcp\ToolSchema;
use Foresi\Shared\Domain\View\SavedView;
use Foresi\Shared\Infrastructure\Mcp\ToolArguments;
use Foresi\Shared\Infrastructure\View\Api\ViewJson;

/**
 * The address book of the organisation, narrowed and paged as GET
 * /api/v1/contacts does it, each contact as the API writes it.
 */
final readonly class SearchContactsTool implements ReadTool
{
    public function __construct(private ContactList $list, private ToolArguments $arguments, private SavedViews $views, private ScopeGuard $scope, private ViewJson $json, private ContactLists $lists)
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

    public function scope(): string
    {
        return 'contacts:read';
    }

    public function description(): string
    {
        return 'Searches the address book of the organisation (customers, suppliers, partners and authorities, companies and persons), newest first. Archived contacts are left out unless archived is true. '
            .'Every argument narrows the search; with none, it returns the newest contacts. '
            .'view_id lists what a saved view holds (find views with foresi_list_views; it needs the views:read scope) and filter adds conditions in the format of foresi_list_lists, such as {"conditions": [{"field": "role", "op": "is", "value": "supplier"}]}; a condition on archived decides it instead of the archived argument. '
            .'Returns {items, next_cursor, applied_view}: each contact has its id, kind, name, roles, registration country and number, VAT id, general e-mail, billing address and whether it is archived. '
            .'When next_cursor is not null, more contacts match: call again with the same arguments and next_cursor as cursor. '
            .'Use foresi_get_contact for the addresses, e-mails, bank accounts and contact persons of one contact; its id is the customer_id of foresi_search_invoices.';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'query' => ['type' => 'string', 'description' => 'Text looked for in the name, the registration number, the VAT id and the e-mail addresses.'],
                'role' => ToolSchema::choiceSchema(array_column(PartyRole::cases(), 'value'), 'Only contacts with this role.'),
                'archived' => ['type' => 'boolean', 'default' => false, 'description' => 'True returns only the archived contacts, false only those that are not.'],
                'registration_number' => ['type' => 'string', 'description' => 'Only the contact with this company registration number; spaces and punctuation are ignored.'],
                'vat_id' => ['type' => 'string', 'description' => 'Only the contact with this VAT id, such as CZ12345678; spaces and case are ignored.'],
                'view_id' => ['type' => 'string', 'description' => 'Only the contacts a saved view of the list party.contacts holds: its id as foresi_list_views gives it, or the system key of a view a module ships, such as "party.contacts.customers".'],
                'filter' => ['type' => 'object', 'description' => 'Conditions all contacts meet, {"conditions": [{"field", "op", "value"}]}, checked against the list party.contacts; its fields and operators are in foresi_list_lists.'],
            ] + ToolSchema::pagingSchema(),
            'additionalProperties' => false,
        ];
    }

    public function call(array $arguments): ToolOutput
    {
        $query = new ContactListQuery(
            ToolSchema::text($arguments, 'query') ?? '',
            ToolSchema::text($arguments, 'role'),
            ToolSchema::flag($arguments, 'archived'),
            ToolSchema::text($arguments, 'registration_number'),
            ToolSchema::text($arguments, 'vat_id'),
            ToolSchema::limit($arguments),
            ToolSchema::text($arguments, 'cursor'),
        );
        $this->arguments->validate($query);
        $afterId = ToolArguments::afterId($query->cursor);
        $viewId = ToolSchema::text($arguments, 'view_id');
        if (null !== $viewId && !$this->scope->allows(AppliedView::VIEWS_SCOPE)) {
            throw new ToolRefusal(ToolRefusal::INSUFFICIENT_SCOPE, \sprintf('The token does not have the "%s" scope a saved view needs.', AppliedView::VIEWS_SCOPE));
        }
        try {
            $applied = AppliedView::of($this->views, $this->scope, $this->lists->list(), $viewId, $arguments['filter'] ?? null);
            $slice = $this->list->slice($query->filter($applied->filter), $afterId, $query->limit);
        } catch (SavedViewRefused $refused) {
            throw ToolRefusal::of($refused);
        } catch (PartyNotAllowed) {
            throw ToolRefusal::forbidden('Only the owners and members of the organisation read its contacts.');
        }
        $page = ToolArguments::page(array_map(ContactJson::row(...), $slice->rows), $slice->hasMore ? $slice->lastId() : null, 'contact', 'contacts');
        if (!$applied->view instanceof SavedView) {
            return new ToolOutput($page->data + ['applied_view' => null], $page->summary);
        }
        $view = $this->json->view($applied->view);

        return new ToolOutput($page->data + ['applied_view' => $view], \sprintf('Through the view %s: %s', \is_string($view['name']) ? $view['name'] : '', $page->summary));
    }
}
