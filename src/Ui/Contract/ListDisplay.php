<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Ui\Contract;

/**
 * How one list shows: its columns in order with their widths and the field it groups by. How
 * tall its rows are is the person's density (Profile, Appearance), the same on every list.
 */
final readonly class ListDisplay
{
    /**
     * @param list<ResolvedColumn> $columns
     * @param array<string, mixed> $context the list's context, which the cells are rendered in
     */
    public function __construct(public array $columns, public array $context = [], public ?string $groupBy = null)
    {
    }

    /**
     * @return list<string>
     */
    public function keys(): array
    {
        return array_map(static fn (ResolvedColumn $column): string => $column->key(), $this->columns);
    }

    /**
     * The Ui:DataTable columns.
     *
     * @param (callable(string): string)|null $href the list's URL sorted by the given sort
     *
     * @return list<array<string, mixed>>
     */
    public function table(string $sort = '', ?callable $href = null): array
    {
        return array_map(static fn (ResolvedColumn $column): array => $column->table($sort, $href), $this->columns);
    }

    /**
     * The cells of one row, keyed by column key, each rendered by its column.
     *
     * @return array<string, mixed>
     */
    public function cells(mixed $row): array
    {
        $cells = [];
        foreach ($this->columns as $column) {
            $cells[$column->key()] = $column->column->cell($row, $this->context);
        }

        return $cells;
    }
}
