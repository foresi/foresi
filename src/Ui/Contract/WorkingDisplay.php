<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Ui\Contract;

/**
 * The display a person works with on a list before anything is saved (spec D10, D-display-3):
 * the list's address carries it next to the view, as cols=number,customer,due_date (the visible
 * columns in order), and on a list that groups its rows,
 * group=<one of its groupings>, and on a list with quick filters, pins=<field keys in order> (empty
 * for none), and widths=<key>:<px>,… (the widths a person dragged the columns to; empty for the
 * view's own). With none, the list shows its view's stored display, or the
 * registry's defaults when the view has none.
 *
 * Both are read leniently through the registry (D-display-2), so a link with a column a module
 * took away still opens, and one with an older key of a column (an alias, DS2) shows it and
 * keeps its key from then on. A working state that shows what the view shows is no change at
 * all and leaves the address. Until saved views keep a person's state (saved_view_user_state),
 * the address is where it lives.
 */
interface WorkingDisplay
{
    public const string COLUMNS = 'cols';
    /** What the list groups by, kept in the payload as group_by (D-display-2). */
    public const string GROUP = 'group';
    /** The quick filters pinned under the tabs, kept in the payload as pinned. */
    public const string PINNED = 'pins';
    /** The widths of the columns as key:px pairs, kept in the payload's columns; empty names the view's own. */
    public const string WIDTHS = 'widths';
    /** The query keys of the working state, which a link to another view leaves out. */
    public const array KEYS = [self::COLUMNS, self::GROUP, self::PINNED, self::WIDTHS];
    /** No column is dragged wider than this many px. */
    public const int MAX_WIDTH = 1200;
    /** At most this many quick filters stand under the tabs (ListDefinition::MAX_PINNED). */
    public const int MAX_PINNED = 4;

    /**
     * @param array<string, mixed>                                    $context the list's context, as the registry of the list's columns takes it
     * @param array<array-key, mixed>|null                            $saved   the view's stored display payload; null for the registry's defaults
     * @param array<array-key, mixed>                                 $query   the list's query: cols as a comma separated string (or a list, as a form without JavaScript sends it), group, pins and widths
     * @param list<string>                                            $groups  what the list can group by, its default first; none for a list that does not group
     * @param array{fields: list<string>, default: list<string>}|null $pins    the fields a quick filter can be pinned for and those pinned by default; null for a list without quick filters
     */
    public function read(string $list, array $context, ?array $saved, array $query, array $groups = [], ?array $pins = null): WorkingState;
}
