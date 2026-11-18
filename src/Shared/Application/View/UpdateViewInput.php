<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Shared\Application\View;

use Foresi\Shared\Application\Api\Field;
use Foresi\Shared\Application\Api\Omitted;
use Foresi\Shared\Domain\View\SavedView;

/**
 * A partial change of a saved view: a field left out keeps its value, columns
 * and density sent as null go back to the list's defaults.
 */
final readonly class UpdateViewInput
{
    /**
     * @param list<mixed>|Omitted      $conditions
     * @param list<mixed>|Omitted      $sort
     * @param list<mixed>|Omitted|null $columns
     */
    public function __construct(
        #[Field('The id of the view, as foresi_list_views gives it.')]
        public string $viewId,
        #[Field('The name on its tab.', maxLength: SavedView::NAME_MAX_LENGTH)]
        public string|Omitted $name = new Omitted(),
        #[Field('private: only its creator sees it; shared: every owner and member sees it. A view a module ships stays shared.', enum: ['private', 'shared'])]
        public string|Omitted $visibility = new Omitted(),
        #[Field('The filter conditions, replacing all of them: {field, op, value}, as foresi_list_lists describes them.', items: 'object')]
        public array|Omitted $conditions = new Omitted(),
        #[Field('The order of the rows, replacing it: {field, dir} with dir asc or desc, at most 3.', items: 'object')]
        public array|Omitted $sort = new Omitted(),
        #[Field('The columns shown, in order: column keys, or {key, width}; null shows the list\'s default columns.', items: 'object')]
        public array|Omitted|null $columns = new Omitted(),
        #[Field('How tight the rows are; null for the list\'s default.', enum: ViewRules::DENSITIES)]
        public string|Omitted|null $density = new Omitted(),
        #[Field('Whether its tab shows how many rows it holds.')]
        public bool|Omitted $showCount = new Omitted(),
        #[Field('primary: a tab of the list; overflow: in its "More views" menu.', enum: ['primary', 'overflow'])]
        public string|Omitted $placement = new Omitted(),
        #[Field('Its place among the views of the list; lower comes first.')]
        public int|Omitted $position = new Omitted(),
        #[Field('A hidden view a module ships is left off the list until it is shown again or the defaults are restored. Only owners hide.')]
        public bool|Omitted $hidden = new Omitted(),
    ) {
    }
}
