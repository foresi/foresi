<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Ui\Component;

use App\Ui\Avatar;
use Symfony\UX\TwigComponent\Attribute\AsTwigComponent;
use Symfony\UX\TwigComponent\Attribute\PostMount;

/**
 * @phpstan-type Cell array{key: string, align: string, numeric: bool, text: string, tone: ?string, title: ?string, link: ?string, hint: ?string, phone: bool, wide: bool, truncate: bool, plain: bool, icon: ?string, iconClass: string, links: list<array{text: string, href: string}>, avatar: ?array{initials: string, tone: string}, badges: list<array{label: string, tone: string}>, flag: ?array{label: string, tone: string, icon: ?string}}
 * @phpstan-type Confirm array{title: string, text: ?string, accept: ?string, cancel: ?string, tone: ?string}
 * @phpstan-type Action array{label: string, href: string, method: string, confirm: ?Confirm, icon: ?string, plain: bool, dialog: bool, fields: array<string, string>}
 * @phpstan-type RowAlert array{text: string, column: string, href: ?string, action: ?string}
 * @phpstan-type RowView array{id: string, href: ?string, label: string, selected: bool, child: bool, unread: ?bool, cells: list<Cell>, actions: list<Action>, secondary: ?string, alert: ?RowAlert}
 * @phpstan-type ColumnView array{key: string, label: string, align: string, sort: ?string, href: ?string, sortable: bool, phone: bool, wide: bool, truncate: bool}
 */
#[AsTwigComponent]
final class DataTable
{
    /** Values longer than this get their full text in a title attribute. */
    private const int TITLE_FROM_LENGTH = 24;
    private const array ALIGNS = ['left', 'right', 'center'];
    /**
     * The share of the room left by the other columns that each truncating column takes, by
     * how many there are. The browser scales the shares down to the room there is.
     */
    private const array SHARES = [1 => 'sm:w-full', 2 => 'sm:w-1/2', 3 => 'sm:w-1/3', 4 => 'sm:w-1/4', 5 => 'sm:w-1/5', 6 => 'sm:w-1/6'];
    /** The colour of the icon of an icon cell, by tone; its text says the same, so colour is never alone. */
    private const array ICON_TONES = ['gray' => 'text-muted', 'green' => 'text-green', 'amber' => 'text-badge-amber-text', 'red' => 'text-badge-red-text'];

    /**
     * @var list<array<string, mixed>> each with a string key and label, optional align, sortable, sort (asc|desc), href,
     *                                 phone (false leaves the column out on a phone), wide (true shows the column on a wide
     *                                 screen only, for one of low value) and truncate (true lets a column of long text give
     *                                 way, cut with an ellipsis and its full text in a title, so the table fits its frame;
     *                                 numbers, badges and the actions never give way)
     */
    public array $columns = [];

    /**
     * @var list<array<string, mixed>> each with cells keyed by column key, optional id, href, plain (the link leaves Turbo), label,
     *                                 selected, child (a row under the row before it, such as a subtask: its first cell
     *                                 indented, its text smaller), unread (true marks a row not read yet, as an inbox
     *                                 does: a dot before the first cell, said to a screen reader too, and its link in
     *                                 bold; false keeps the room of the dot and sets the link in normal weight), secondary (the line a phone shows under the first cell), alert (what went wrong
     *                                 with the row: a text, the column key it shows under on a wider screen, and optionally an
     *                                 href and action label of the place that fixes it; a phone shows it as a line of its own
     *                                 under the first cell) and actions, each with a label, href and method, optional
     *                                 confirm, icon (shown in the row, else in its menu), plain and fields (hidden values of
     *                                 a POST)
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

    /**
     * The width of a truncating column: an even share of what the columns that keep their
     * width leave. With no width of its own it could not be narrower than its text.
     */
    public function shareClass(): string
    {
        $count = \count(array_filter($this->columnViews, static fn (array $column): bool => $column['truncate']));

        return self::SHARES[min(max($count, 1), \count(self::SHARES))];
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
            'wide' => true === ($column['wide'] ?? false),
            'truncate' => true === ($column['truncate'] ?? false),
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
            'child' => true === ($row['child'] ?? false),
            'unread' => isset($row['unread']) ? true === $row['unread'] : null,
            'cells' => $views,
            'actions' => array_values(array_map($this->action(...), $actions)),
            'secondary' => is_string($secondary) && '' !== $secondary ? $secondary : null,
            'alert' => $this->alert($row['alert'] ?? null),
        ];
    }

    /**
     * @return RowAlert|null
     */
    private function alert(mixed $alert): ?array
    {
        if (null === $alert) {
            return null;
        }
        if (!is_array($alert)) {
            throw new \InvalidArgumentException('A row alert must be an array.');
        }
        $text = isset($alert['text']) && is_string($alert['text']) && '' !== $alert['text'] ? $alert['text'] : throw new \InvalidArgumentException('A row alert needs a text.');
        $column = isset($alert['column']) && is_string($alert['column']) ? $alert['column'] : throw new \InvalidArgumentException('A row alert needs the column it shows under.');
        if (!in_array($column, array_column($this->columnViews, 'key'), true)) {
            throw new \InvalidArgumentException('A row alert shows under a column of the table.');
        }
        $href = isset($alert['href']) && is_string($alert['href']) ? $alert['href'] : null;
        Style::href($href);
        $action = isset($alert['action']) && is_string($alert['action']) && '' !== $alert['action'] ? $alert['action'] : null;
        if ((null === $href) !== (null === $action)) {
            throw new \InvalidArgumentException('A row alert has both an href and an action label, or neither.');
        }

        return ['text' => $text, 'column' => $column, 'href' => $href, 'action' => $action];
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
            'confirm' => $this->confirm($action['confirm'] ?? null),
            'icon' => $icon,
            'plain' => true === ($action['plain'] ?? false),
            'dialog' => 'GET' === $method && true === ($action['dialog'] ?? false),
            'fields' => $fields,
        ];
    }

    /**
     * A confirmation is the question alone, or the question with its consequence,
     * the labels of the two answers and the danger tone.
     *
     * @return Confirm|null
     */
    private function confirm(mixed $confirm): ?array
    {
        if (null === $confirm) {
            return null;
        }
        if (is_string($confirm)) {
            $confirm = ['title' => $confirm];
        }
        if (!is_array($confirm)) {
            throw new \InvalidArgumentException('A confirmation is a question or an array with a title.');
        }
        $optional = static fn (string $key): ?string => isset($confirm[$key]) && is_string($confirm[$key]) && '' !== $confirm[$key] ? $confirm[$key] : null;

        return [
            'title' => Style::requireString($confirm, 'title', 'A confirmation'),
            'text' => $optional('text'),
            'accept' => $optional('accept'),
            'cancel' => $optional('cancel'),
            'tone' => $optional('tone'),
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
        $icon = null;
        $iconClass = '';
        $links = [];
        $avatar = null;
        $badges = [];
        $flag = null;
        if (is_array($value) && isset($value['flag'])) {
            // A text with a badge after it that marks the row, such as a private task.
            $text = isset($value['text']) && is_string($value['text']) && '' !== $value['text'] ? $value['text'] : throw new \InvalidArgumentException('A flagged cell needs a text.');
            $flagIcon = is_array($value['flag']) && isset($value['flag']['icon']) && is_string($value['flag']['icon']) ? $value['flag']['icon'] : null;
            Style::icon($flagIcon);
            $flag = Style::badge($value['flag'], 'A table cell flag') + ['icon' => $flagIcon];
        } elseif (is_array($value) && isset($value['badges'])) {
            // Several badges in one cell, such as the labels of a task.
            $badges = $this->badges($value['badges']);
            $text = implode(', ', array_column($badges, 'label'));
        } elseif (is_array($value) && isset($value['avatar'])) {
            // A person: the initials avatar, coloured by a stable seed such as the user id, before the name.
            $seed = is_string($value['avatar']) ? $value['avatar'] : throw new \InvalidArgumentException('An avatar seed must be a string.');
            $text = isset($value['text']) && is_string($value['text']) && '' !== $value['text'] ? $value['text'] : throw new \InvalidArgumentException('An avatar cell needs a name.');
            $avatar = ['initials' => Avatar::initials($text), 'tone' => Avatar::tone($seed)];
        } elseif (is_array($value) && isset($value['links'])) {
            // Several links in one cell, such as the invoices a payment paid; plain leaves Turbo.
            $links = $this->links($value['links']);
            $plain = true === ($value['plain'] ?? false);
            $text = implode(', ', array_column($links, 'text'));
        } elseif (is_array($value) && isset($value['icon'])) {
            // A label with an icon in front and its explanation as a tooltip, such as how an invoice was sent.
            $icon = is_string($value['icon']) ? $value['icon'] : throw new \InvalidArgumentException('A cell icon must be a string.');
            Style::icon($icon);
            $iconTone = $value['tone'] ?? 'gray';
            $iconClass = is_string($iconTone) && isset(self::ICON_TONES[$iconTone]) ? self::ICON_TONES[$iconTone] : throw new \InvalidArgumentException('Unknown icon tone.');
            $text = isset($value['text']) && is_string($value['text']) && '' !== $value['text'] ? $value['text'] : throw new \InvalidArgumentException('An icon cell needs a text.');
            $hint = isset($value['hint']) && is_string($value['hint']) ? $value['hint'] : null;
        } elseif (is_array($value)) {
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
            // A truncating column can be cut short at any length, so its text is always in a title.
            'title' => null === $icon && ($column['truncate'] ? '' !== $text : mb_strlen($text) > self::TITLE_FROM_LENGTH) ? $text : null,
            'link' => $link,
            'hint' => $hint,
            'phone' => $column['phone'],
            'wide' => $column['wide'],
            'truncate' => $column['truncate'],
            'plain' => $plain,
            'icon' => $icon,
            'iconClass' => $iconClass,
            'links' => $links,
            'avatar' => $avatar,
            'badges' => $badges,
            'flag' => $flag,
        ];
    }

    /**
     * @return list<array{label: string, tone: string}>
     */
    private function badges(mixed $badges): array
    {
        if (!is_array($badges) || [] === $badges) {
            throw new \InvalidArgumentException('A badges cell needs a list of badges.');
        }

        return array_values(array_map(static fn (mixed $badge): array => Style::badge($badge, 'A badge of a cell'), $badges));
    }

    /**
     * @return list<array{text: string, href: string}>
     */
    private function links(mixed $links): array
    {
        if (!is_array($links) || [] === $links) {
            throw new \InvalidArgumentException('A links cell needs a list of links.');
        }
        $views = [];
        foreach ($links as $link) {
            if (!is_array($link)) {
                throw new \InvalidArgumentException('A link of a cell must be an array.');
            }
            $href = Style::requireString($link, 'href', 'A link of a cell');
            Style::href($href);
            $views[] = ['text' => Style::requireString($link, 'text', 'A link of a cell'), 'href' => $href];
        }

        return $views;
    }
}
