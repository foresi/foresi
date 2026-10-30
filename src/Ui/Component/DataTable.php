<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Ui\Component;

use Symfony\UX\TwigComponent\Attribute\AsTwigComponent;
use Symfony\UX\TwigComponent\Attribute\PostMount;

/**
 * @phpstan-type Cell array{key: string, align: string, numeric: bool, text: string, tone: ?string, title: ?string, link: ?string}
 * @phpstan-type RowView array{id: string, href: ?string, label: string, selected: bool, cells: list<Cell>}
 * @phpstan-type ColumnView array{key: string, label: string, align: string, sort: ?string, href: ?string, sortable: bool}
 */
#[AsTwigComponent]
final class DataTable
{
    /** Values longer than this get their full text in a title attribute. */
    private const int TITLE_FROM_LENGTH = 24;
    private const array ALIGNS = ['left', 'right', 'center'];

    /**
     * @var list<array<string, mixed>> each with a string key and label, optional align, sortable, sort (asc|desc) and href
     */
    public array $columns = [];

    /**
     * @var list<array<string, mixed>> each with cells keyed by column key, optional id, href, label and selected
     */
    public array $rows = [];

    /**
     * @var array<string, scalar|null>|null totals per column key
     */
    public ?array $totals = null;
    public bool $selectable = false;

    /** Form field name of the row checkboxes, so a surrounding form submits the selected ids. */
    public ?string $selectionName = null;
    public ?string $caption = null;

    /** @var list<ColumnView> */
    private array $columnViews = [];

    /** @var list<RowView> */
    private array $rowViews = [];

    #[PostMount]
    public function validate(): void
    {
        foreach ($this->columns as $column) {
            $this->columnViews[] = $this->column($column);
        }
        foreach ($this->rows as $row) {
            $this->rowViews[] = $this->row($row);
        }
    }

    /**
     * @return list<ColumnView>
     */
    public function columnViews(): array
    {
        return $this->columnViews;
    }

    /**
     * @return list<RowView>
     */
    public function rowViews(): array
    {
        return $this->rowViews;
    }

    /**
     * @return list<array{key: string, align: string, text: string}>
     */
    public function totalCells(): array
    {
        $cells = [];
        foreach ($this->columnViews as $column) {
            $value = $this->totals[$column['key']] ?? null;
            $cells[] = ['key' => $column['key'], 'align' => $column['align'], 'text' => null === $value ? '' : (string) $value];
        }

        return $cells;
    }

    public function alignClass(string $align): string
    {
        return match ($align) {
            'right' => 'text-right',
            'center' => 'text-center',
            default => 'text-left',
        };
    }

    public function ariaSort(?string $sort): string
    {
        return match ($sort) {
            'asc' => 'ascending',
            'desc' => 'descending',
            default => 'none',
        };
    }

    /**
     * @param array<array-key, mixed> $column
     *
     * @return ColumnView
     */
    private function column(array $column): array
    {
        $align = $column['align'] ?? 'left';
        if (!is_string($align) || !in_array($align, self::ALIGNS, true)) {
            throw new \InvalidArgumentException(sprintf('Column align must be one of: %s.', implode(', ', self::ALIGNS)));
        }
        $sortable = true === ($column['sortable'] ?? false);
        $sort = $column['sort'] ?? null;
        if (null !== $sort && !in_array($sort, ['asc', 'desc'], true)) {
            throw new \InvalidArgumentException('Column sort must be "asc", "desc" or null.');
        }
        $href = null;
        if ($sortable) {
            $href = Style::requireString($column, 'href', 'A sortable column');
            Style::href($href);
        }

        return [
            'key' => Style::requireString($column, 'key', 'A column'),
            'label' => Style::requireString($column, 'label', 'A column'),
            'align' => $align,
            'sort' => $sortable && is_string($sort) ? $sort : null,
            'href' => $href,
            'sortable' => $sortable,
        ];
    }

    /**
     * @param array<array-key, mixed> $row
     *
     * @return RowView
     */
    private function row(array $row): array
    {
        $cells = $row['cells'] ?? null;
        if (!is_array($cells)) {
            throw new \InvalidArgumentException('A row needs a "cells" array.');
        }
        $id = isset($row['id']) && is_scalar($row['id']) ? (string) $row['id'] : '';
        if ($this->selectable && '' === $id) {
            throw new \InvalidArgumentException('Rows of a selectable table need an id.');
        }
        $href = isset($row['href']) && is_string($row['href']) ? $row['href'] : null;
        Style::href($href);

        $views = [];
        $first = true;
        foreach ($this->columnViews as $column) {
            $views[] = $this->cell($column, $cells[$column['key']] ?? null, $first && null !== $href ? $href : null);
            $first = false;
        }
        $label = isset($row['label']) && is_string($row['label']) ? $row['label'] : ($views[0]['text'] ?? $id);

        return ['id' => $id, 'href' => $href, 'label' => $label, 'selected' => true === ($row['selected'] ?? false), 'cells' => $views];
    }

    /**
     * @param ColumnView $column
     *
     * @return Cell
     */
    private function cell(array $column, mixed $value, ?string $link): array
    {
        $tone = null;
        if (is_array($value)) {
            $badge = Style::badge($value + ['label' => $value['text'] ?? null], 'A table cell');
            $tone = $badge['tone'];
            $text = $badge['label'];
        } elseif (null === $value) {
            $text = '';
        } elseif (is_scalar($value)) {
            $text = (string) $value;
        } else {
            throw new \InvalidArgumentException('A cell value must be a scalar, null or an array with text and tone.');
        }

        return [
            'key' => $column['key'],
            'align' => $column['align'],
            'numeric' => 'right' === $column['align'],
            'text' => $text,
            'tone' => $tone,
            'title' => mb_strlen($text) > self::TITLE_FROM_LENGTH ? $text : null,
            'link' => $link,
        ];
    }
}
