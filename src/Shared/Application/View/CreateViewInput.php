<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Shared\Application\View;

use Foresi\Shared\Application\Api\Field;
use Foresi\Shared\Domain\View\SavedView;

/**
 * A new saved view on a list (spec D2, D6): its filter conditions (D4, joined
 * by AND), its sort and its columns, private to the person who makes it or
 * shared with the staff.
 */
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
        #[Field('The filter conditions, all of which a row meets: {field, op, value}, such as {"field": "due_date", "op": "between", "value": [{"relative": "today"}, {"relative": "next_n_days", "n": 7}]}. The fields, their operators and values are in foresi_list_lists.', items: 'object')]
        public array $conditions = [],
        #[Field('The order of the rows: {field, dir} with dir asc or desc, at most 3.', items: 'object')]
        public array $sort = [],
        #[Field('The columns shown, in order: column keys from foresi_list_lists, or {key, width} with the width in px; null shows the list\'s default columns.', items: 'object')]
        public ?array $columns = null,
        #[Field('How tight the rows are; null for the list\'s default.', enum: ViewRules::DENSITIES)]
        public ?string $density = null,
        #[Field('Whether its tab shows how many rows it holds.')]
        public bool $showCount = false,
        #[Field('primary: a tab of the list; overflow: in its "More views" menu.', enum: ['primary', 'overflow'])]
        public string $placement = 'primary',
    ) {
    }
}
