<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Ui\Contract;

/**
 * What WorkingDisplay read: how the list shows now, the display payload that shows it (for the
 * rows a list renders in a context of their own), whether it differs from the view, and the
 * query that keeps it in the list's links. On a list with quick filters it says which fields are
 * pinned under the tabs, in their order.
 */
final readonly class WorkingState
{
    /**
     * @param array<array-key, mixed>|null $payload the display payload the list shows; null for the registry's defaults
     * @param list<ResolvedColumn>         $columns every column the list's context offers, in the default order
     * @param array<string, string>        $query   cols, density and pins, only when they differ from the view
     * @param list<string>                 $pinned  the keys of the fields pinned as quick filters, in order
     */
    public function __construct(
        public ListDisplay $display,
        public ?array $payload,
        private array $columns,
        public string $density,
        public bool $modified,
        public array $query,
        public array $pinned = [],
    ) {
    }

    /**
     * The Ui:DataTable density of the rows.
     */
    public function tableDensity(): string
    {
        return ListDisplay::DENSE === $this->density ? 'dense' : 'list';
    }

    /**
     * The columns of the Settings sheet (Ui:ColumnPicker): the shown ones in their order, then the
     * hidden ones the context offers, in the default order.
     *
     * @return list<array{key: string, label: string, visible: bool, locked: bool}>
     */
    public function picker(): array
    {
        $items = [];
        foreach ($this->display->columns as $column) {
            $items[$column->key()] = ['key' => $column->key(), 'label' => $column->label, 'visible' => true, 'locked' => $column->column->locked];
        }
        foreach ($this->columns as $column) {
            $items[$column->key()] ??= ['key' => $column->key(), 'label' => $column->label, 'visible' => false, 'locked' => $column->column->locked];
        }

        return array_values($items);
    }
}
