<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Shared\Application\View;

use Foresi\Shared\Application\Api\ScopeGuard;
use Foresi\Shared\Domain\View\SavedView;

/**
 * A saved view and a filter sent with a search of one list (spec D13), read once for
 * the REST API and MCP alike: the view's conditions and the filter's, joined by AND.
 * Reading through a view needs the views:read scope besides the list's own; a filter
 * needs nothing more.
 *
 * - A view of another list is refused, as its conditions mean nothing here.
 * - A filter is D4 JSON, {"conditions": [...]}. A bad one is refused with its path,
 *   such as "filter.conditions[0].op".
 */
final readonly class AppliedView
{
    public const string VIEWS_SCOPE = 'views:read';

    private function __construct(public ?ViewFilter $filter, public ?SavedView $view)
    {
    }

    /**
     * @param mixed $filter the filter as JSON text, or already decoded as MCP sends it
     *
     * @throws SavedViewRefused
     * @throws \DomainException when the token lacks views:read for a view
     */
    public static function of(SavedViews $views, ScopeGuard $scope, ListDefinition $list, ?string $viewId, mixed $filter): self
    {
        $saved = null;
        $conditions = null;
        if (null !== $viewId && '' !== $viewId) {
            $scope->require(self::VIEWS_SCOPE);
            $saved = $views->get($viewId);
            if ($saved->listKey() !== $list->key) {
                throw SavedViewRefused::invalid('view_list_mismatch', 'view', \sprintf('The view is of the list "%s", not "%s"; use the search of its list.', $saved->listKey(), $list->key));
            }
            $conditions = $views->filterOf($saved);
        }
        if (null !== $filter && '' !== $filter) {
            $conditions ??= new ViewFilter();
            foreach (self::parse($filter, $list)->conditions as $condition) {
                $conditions = $conditions->with($condition);
            }
        }

        return new self($conditions, $saved);
    }

    private static function parse(mixed $filter, ListDefinition $list): ViewFilter
    {
        if (\is_string($filter)) {
            try {
                $filter = json_decode($filter, true, flags: \JSON_THROW_ON_ERROR);
            } catch (\JsonException) {
                throw SavedViewRefused::invalid('filter_invalid', 'filter', 'filter is a JSON object, {"conditions": [{"field": …, "op": …, "value": …}]}.');
            }
        }
        try {
            return ViewFilter::parse($filter, $list);
        } catch (InvalidFilter $invalid) {
            throw SavedViewRefused::invalid('filter_invalid', 'filters' === $invalid->path ? 'filter' : 'filter.'.$invalid->path, $invalid->getMessage().' Find the fields and operators with GET /api/v1/lists or foresi_list_lists.');
        }
    }
}
