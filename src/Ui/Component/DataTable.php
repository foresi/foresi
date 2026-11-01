<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Ui\Component;

use Symfony\UX\TwigComponent\Attribute\AsTwigComponent;
use Symfony\UX\TwigComponent\Attribute\PostMount;

/**
 * @phpstan-type Cell array{key: string, align: string, numeric: bool, text: string, tone: ?string, title: ?string, link: ?string, hint: ?string, phone: bool, plain: bool}
 * @phpstan-type Action array{label: string, href: string, method: string, confirm: ?string, icon: ?string, plain: bool, fields: array<string, string>}
 * @phpstan-type RowView array{id: string, href: ?string, label: string, selected: bool, cells: list<Cell>, actions: list<Action>, secondary: ?string}
 * @phpstan-type ColumnView array{key: string, label: string, align: string, sort: ?string, href: ?string, sortable: bool, phone: bool}
 */
#[AsTwigComponent]
final class DataTable
{
    /** Values longer than this get their full text in a title attribute. */
    private const int TITLE_FROM_LENGTH = 24;
    private const array ALIGNS = ['left', 'right', 'center'];

    /**
     * @var list<array<string, mixed>> each with a string key and label, optional align, sortable, sort (asc|desc), href and
     *                                 phone (false leaves the column out on a phone)
     */
    public array $columns = [];

    /**
     * @var list<array<string, mixed>> each with cells keyed by column key, optional id, href, plain (the link leaves Turbo), label,
     *                                 selected, secondary (the line a phone shows under the first cell) and actions, each with
     *                                 a label, href and method, optional confirm, icon (shown in the row, else in its menu),
     *                                 plain and fields (hidden values of a POST)
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

    /** The CSRF token the POST actions of the rows send. */
    public ?string $actionToken = null;

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

    public function hasActions(): bool
    {
        return array_any($this->rowViews, static fn (array $row): bool => [] !== $row['actions']);
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
            'phone' => false !== ($column['phone'] ?? true),
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
        $plain = true === ($row['plain'] ?? false);

        $views = [];
        $first = true;
        foreach ($this->columnViews as $column) {
            $views[] = $this->cell($column, $cells[$column['key']] ?? null, $first && null !== $href ? $href : null, $plain);
            $first = false;
        }
        $label = isset($row['label']) && is_string($row['label']) ? $row['label'] : ($views[0]['text'] ?? $id);

        $actions = $row['actions'] ?? [];
        if (!is_array($actions)) {
            throw new \InvalidArgumentException('Row actions must be a list.');
        }
        $secondary = $row['secondary'] ?? null;

        return [
            'id' => $id,
            'href' => $href,
            'label' => $label,
            'selected' => true === ($row['selected'] ?? false),
            'cells' => $views,
            'actions' => array_values(array_map($this->action(...), $actions)),
            'secondary' => is_string($secondary) && '' !== $secondary ? $secondary : null,
        ];
    }

    /**
     * @return Action
     */
    private function action(mixed $action): array
    {
        if (!is_array($action)) {
            throw new \InvalidArgumentException('A row action must be an array.');
        }
        $method = $action['method'] ?? 'GET';
        if ('GET' !== $method && 'POST' !== $method) {
            throw new \InvalidArgumentException('A row action goes by GET or POST.');
        }
        $href = Style::requireString($action, 'href', 'A row action');
        Style::href($href);
        $icon = isset($action['icon']) && is_string($action['icon']) ? $action['icon'] : null;
        Style::icon($icon);
        $confirm = $action['confirm'] ?? null;
        $fields = [];
        foreach (is_array($action['fields'] ?? null) ? $action['fields'] : [] as $name => $value) {
            if (is_scalar($value)) {
                $fields[(string) $name] = (string) $value;
            }
        }

        return [
            'label' => Style::requireString($action, 'label', 'A row action'),
            'href' => $href,
            'method' => $method,
            'confirm' => is_string($confirm) ? $confirm : null,
            'icon' => $icon,
            'plain' => true === ($action['plain'] ?? false),
            'fields' => $fields,
        ];
    }

    /**
     * @param ColumnView $column
     *
     * @return Cell
     */
    private function cell(array $column, mixed $value, ?string $link, bool $plain): array
    {
        $tone = null;
        $hint = null;
        if (is_array($value)) {
            $badge = Style::badge($value + ['label' => $value['text'] ?? null], 'A table cell');
            $tone = $badge['tone'];
            $text = $badge['label'];
            $hint = isset($value['hint']) && is_string($value['hint']) ? $value['hint'] : null;
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
            'hint' => $hint,
            'phone' => $column['phone'],
            'plain' => $plain,
        ];
    }
}
