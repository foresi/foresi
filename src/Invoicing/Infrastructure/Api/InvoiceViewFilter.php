<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Invoicing\Infrastructure\Api;

use Foresi\Invoicing\Application\View\InvoiceLists;
use Foresi\Invoicing\Contract\DocumentKind;
use Foresi\Shared\Application\Api\ScopeGuard;
use Foresi\Shared\Application\View\InvalidFilter;
use Foresi\Shared\Application\View\SavedViewRefused;
use Foresi\Shared\Application\View\SavedViews;
use Foresi\Shared\Application\View\ViewFilter;
use Foresi\Shared\Domain\View\SavedView;

/**
 * A saved view and a filter sent with a search of the documents (spec D13), read
 * once for the REST API and MCP alike: the view's conditions and the filter's,
 * joined by AND, and the kind they narrow to. Reading through a view needs the
 * views:read scope besides invoices:read; a filter needs nothing more.
 *
 * - A view is one of the four lists of documents; with no kind given it narrows
 *   the search to the kind of its list, and a kind of another list is refused.
 * - A filter is D4 JSON, {"conditions": [...]}, checked against the list of the
 *   kind, or of the invoices without one. A bad one is refused with its path,
 *   such as "filter.conditions[0].op".
 */
final readonly class InvoiceViewFilter
{
    public const string VIEWS_SCOPE = 'views:read';

    private function __construct(public ?DocumentKind $kind, public ?ViewFilter $filter, public ?SavedView $view)
    {
    }

    /**
     * @param mixed $filter the filter as JSON text, or already decoded as MCP sends it
     *
     * @throws SavedViewRefused
     * @throws \DomainException when the token lacks views:read for a view
     */
    public static function of(SavedViews $views, ScopeGuard $scope, ?string $kind, ?string $viewId, mixed $filter): self
    {
        $documents = null === $kind ? null : DocumentKind::tryFrom($kind);
        $saved = null;
        $conditions = null;
        if (null !== $viewId && '' !== $viewId) {
            $scope->require(self::VIEWS_SCOPE);
            $saved = $views->get($viewId);
            $ofView = array_search($saved->listKey(), InvoiceLists::KEYS, true);
            if (!\is_string($ofView)) {
                throw SavedViewRefused::invalid('view_list_mismatch', 'view', \sprintf('The view is of the list "%s", not of the documents; use the search of its list.', $saved->listKey()));
            }
            if ($documents instanceof DocumentKind && $documents->value !== $ofView) {
                throw SavedViewRefused::invalid('view_list_mismatch', 'view', \sprintf('The view is of the list "%s", which holds the kind %s, not %s.', $saved->listKey(), $ofView, $documents->value));
            }
            $documents = DocumentKind::from($ofView);
            $conditions = $views->filterOf($saved);
        }
        if (null !== $filter && '' !== $filter) {
            $parsed = self::parse($filter, $documents ?? DocumentKind::Invoice);
            foreach ($parsed->conditions as $condition) {
                $conditions = ($conditions ?? new ViewFilter())->with($condition);
            }
            $conditions ??= new ViewFilter();
        }

        return new self($documents, $conditions, $saved);
    }

    private static function parse(mixed $filter, DocumentKind $kind): ViewFilter
    {
        if (\is_string($filter)) {
            try {
                $filter = json_decode($filter, true, 16, \JSON_THROW_ON_ERROR);
            } catch (\JsonException) {
                throw SavedViewRefused::invalid('filter_invalid', 'filter', 'filter is a JSON object, {"conditions": [{"field": …, "op": …, "value": …}]}.');
            }
        }
        try {
            return ViewFilter::parse($filter, InvoiceLists::of($kind));
        } catch (InvalidFilter $invalid) {
            throw SavedViewRefused::invalid('filter_invalid', 'filters' === $invalid->path ? 'filter' : 'filter.'.$invalid->path, $invalid->getMessage().' Find the fields and operators with GET /api/v1/lists or foresi_list_lists.');
        }
    }
}
