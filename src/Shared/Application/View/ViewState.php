<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Shared\Application\View;

/**
 * The one place that decides whether a list shows changes nobody saved into its view
 * (spec D10): its filters (f=), its sort or its display (cols=, density=) differ from
 * the view's. The search (q=) never counts, as it is never saved (D8). The view's tab
 * then shows a dot and the bar offers Save, Save as new view and Reset.
 *
 * A list's sort in the address is a field, with a minus for descending ("-dueDate");
 * a view keeps it as [{field, dir}], none for the list's own order.
 */
final class ViewState
{
    public const string SORT = 'sort';

    /**
     * @param list<array{field: string, dir: string}> $workingSort
     */
    public static function isModified(ListView $view, ViewFilter $working, array $workingSort, bool $displayModified): bool
    {
        return $displayModified || $working->toArray() !== $view->filter->toArray() || $workingSort !== $view->sort;
    }

    /**
     * @return list<array{field: string, dir: string}>
     */
    public static function sortOf(string $param): array
    {
        $field = ltrim($param, '-');

        return '' === $field ? [] : [['field' => $field, 'dir' => str_starts_with($param, '-') ? 'desc' : 'asc']];
    }

    /**
     * The address's sort of a view's, or the given default when the view keeps none.
     *
     * @param list<array{field: string, dir: string}> $sort
     */
    public static function sortParam(array $sort, string $default = ''): string
    {
        $first = $sort[0] ?? null;

        return null === $first ? $default : ('desc' === $first['dir'] ? '-' : '').$first['field'];
    }
}
