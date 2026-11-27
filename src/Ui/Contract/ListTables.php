<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Ui\Contract;

use Foresi\Shared\Application\View\ListDefinition;
use Foresi\Shared\Application\View\ListSlot;
use Foresi\Shared\Application\View\ListView;
use Foresi\Shared\Application\View\ViewFilter;

/**
 * A short table of records a page holds in memory as a list with views
 * (docs/design/list-pages.md, rules 8 and 9), for a module's own pages and the pages an
 * integration of a module adds to the interface; Ui\View\ListTable builds it.
 */
interface ListTables
{
    /**
     * Where the builder's Apply (spec D11) leads: the table's address with its rows read into
     * the working filter, which is left out when it is the view's own; null when the request is
     * no Apply of this table.
     *
     * @return array<array-key, mixed>|null the page's whole query
     */
    public function built(ListSlot $slot, ListDefinition $list): ?array;

    /**
     * @template T of object
     *
     * @param string                                          $columns  the list's key in the column registry
     * @param string                                          $route    the page's route, which has no parameters
     * @param string                                          $id       the table's id among the page's, for its filter and columns sheets
     * @param list<T>                                         $records  every record the table can show, in their order
     * @param \Closure(T, WorkingState): array<string, mixed> $row      a row of Ui:DataTable
     * @param \Closure(T, string): mixed                      $valueOf  the value of a field of the list on a record, as RowConditions compares it
     * @param \Closure(T): string                             $searched the text the search looks in
     * @param string|null                                     $hidden   a boolean field whose records show only while the filter names it, as a trash view does
     * @param \Closure(ViewFilter): array<string, mixed>|null $context  the columns' context of the filter shown, such as the trash
     * @param \Closure(ListView): bool|null                   $offered  which views the person gets as tabs, such as the invitations only the owner keeps
     */
    public function of(ListSlot $slot, ListDefinition $list, string $columns, string $route, string $id, array $records, \Closure $row, \Closure $valueOf, \Closure $searched, string $searchLabel, ?string $hidden = null, ?\Closure $context = null, ?\Closure $offered = null): ListTablePage;
}
