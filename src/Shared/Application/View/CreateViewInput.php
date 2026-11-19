<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Shared\Application\View;

use Foresi\Shared\Application\Api\Field;
use Foresi\Shared\Application\Api\Operation;
use Foresi\Shared\Domain\View\SavedView;

/**
 * A new saved view on a list (spec D2, D6): its filter conditions (D4, joined
 * by AND), its sort and its columns, private to the person who makes it or
 * shared with the staff.
 */
#[Operation(
    name: 'views.create',
    method: 'POST',
    path: '/api/v1/views',
    tool: 'foresi_create_view',
    scope: 'views:write',
    title: 'Create a saved view',
    description: 'Saves a view of a list, such as "Due this week" on the invoices: filter conditions joined by AND, a sort and columns, private to you or shared with every owner and member. Find the lists, their fields, operators, values and relative date rules with foresi_list_lists, and the views a list has with foresi_list_views. List the records of a view with view_id on the search tools, such as foresi_search_invoices.',
    status: 201,
    response: 'SavedView',
)]
final readonly class CreateViewInput
{
    /**
     * @param list<mixed>      $conditions
     * @param list<mixed>      $sort
     * @param list<mixed>|null $columns
     */
    public function __construct(
        #[Field('The list the view belongs to, such as "invoicing.invoices". Find the lists and what they filter with foresi_list_lists.', maxLength: 80)]
        public string $listKey,
        #[Field('The name on its tab, such as "Due this week".', maxLength: SavedView::NAME_MAX_LENGTH)]
        public string $name,
        #[Field('private: only you see it; shared: every owner and member sees it.', enum: ['private', 'shared'])]
        public string $visibility = 'private',
        #[Field('The filter conditions, all of which a row meets: {field, op, value}, such as {"field": "due_date", "op": "is", "value": {"relative": "next_n_days", "n": 7}}; a range is {"from": …, "to": …} with op between. The fields, their operators and values are in foresi_list_lists.', items: Field::OBJECTS)]
        public array $conditions = [],
        #[Field('The order of the rows: {field, dir} with dir asc or desc, at most 3.', items: Field::OBJECTS)]
        public array $sort = [],
        #[Field('The columns shown, in order: column keys from foresi_list_lists, or {key, width} with the width in px; null shows the list\'s default columns.', items: Field::OBJECTS_OR_STRINGS)]
        public ?array $columns = null,
        #[Field('How tight the rows are; null for the list\'s default.', enum: ViewRules::DENSITIES)]
        public ?string $density = null,
        #[Field('Whether its tab shows how many rows it holds.')]
        public bool $showCount = false,
        #[Field('primary: a tab of the list; overflow: in its "More views" menu.', enum: ['primary', 'overflow'])]
        public string $placement = 'primary',
        #[Field('What the rows are grouped by, on a list that groups them, such as "person" on the time report; null for the list\'s default.', maxLength: 64)]
        public ?string $groupBy = null,
    ) {
    }
}
