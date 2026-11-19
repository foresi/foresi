<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Ui\Column;

use Foresi\Ui\Contract\Column;

/**
 * A column as one list shows it: its declaration, its translated label and the width the view
 * gives it (null sizes it to its content).
 */
final readonly class ResolvedColumn
{
    public function __construct(public Column $column, public string $label, public ?int $width = null)
    {
    }

    public function key(): string
    {
        return $this->column->key;
    }

    /**
     * The column as a Ui:DataTable column. A column with a sort key links its header to the
     * list sorted by it, ascending first, then descending.
     *
     * @param string                          $sort the list's sort, such as "due_date" or "-due_date"
     * @param (callable(string): string)|null $href the list's URL sorted by the given sort
     *
     * @return array<string, mixed>
     */
    public function table(string $sort = '', ?callable $href = null): array
    {
        $column = $this->column;
        $table = ['key' => $column->key, 'label' => $this->label, 'align' => $column->align, 'phone' => $column->phone];
        if ($column->wide) {
            $table['wide'] = true;
        }
        if ($column->truncate) {
            $table['truncate'] = true;
        }
        if (null !== $this->width) {
            $table['width'] = $this->width;
        }
        $key = $column->sortKey;
        if (null === $key || null === $href) {
            return $table;
        }

        return $table + [
            'sortable' => true,
            'sort' => $sort === $key ? 'asc' : ($sort === '-'.$key ? 'desc' : null),
            'href' => $href($sort === $key ? '-'.$key : $key),
        ];
    }
}
