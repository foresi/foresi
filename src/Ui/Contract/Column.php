<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Ui\Contract;

/**
 * One column a module declares for a list (ListColumns). Its key is stable: a saved view keeps
 * the columns by key, never by position. The cell renderer turns one row of the list, as the
 * list's own module passes it, into the value of a Ui:DataTable cell: a text, or an array the
 * table reads (text with a tone, badges, links and so on).
 *
 * A column is shown by default when it is defaultVisible and available in the list's context;
 * the default order is by defaultPosition. A locked column, the one that names the row, cannot
 * be hidden. A column is sortable only by a sortKey the list's server query supports.
 */
final readonly class Column
{
    public const int MIN_WIDTH = 64;
    public const array ALIGNS = ['left', 'right', 'center'];

    /** The cell renderer; each list types the row it passes, so the column cannot type it here. */
    private \Closure $cell;

    /** @var (\Closure(array<string, mixed>): bool)|null */
    private ?\Closure $available;

    /**
     * @param string                                      $label        the translation key of the header
     * @param callable                                    $cell         the cell of one row, given the row as the list passes it and the list's context
     * @param int|null                                    $defaultWidth in px; null sizes the column to its content
     * @param bool                                        $phone        false leaves the column out on a phone
     * @param bool                                        $wide         true shows the column on a wide screen only
     * @param bool                                        $truncate     true lets the column give way, cut with an ellipsis
     * @param (callable(array<string, mixed>): bool)|null $available    whether the list's context offers the column at all
     */
    public function __construct(
        public string $key,
        public string $label,
        callable $cell,
        public bool $defaultVisible = true,
        public int $defaultPosition = 0,
        public ?int $defaultWidth = null,
        public int $minWidth = self::MIN_WIDTH,
        public string $align = 'left',
        public ?string $sortKey = null,
        public bool $locked = false,
        public bool $phone = true,
        public bool $wide = false,
        public bool $truncate = false,
        ?callable $available = null,
    ) {
        if (1 !== preg_match('/^[a-z][A-Za-z0-9_.]*$/', $key)) {
            throw new \InvalidArgumentException(\sprintf('A column key is a stable identifier such as "dueDate", not "%s".', $key));
        }
        if ('' === $label) {
            throw new \InvalidArgumentException('A column needs a label.');
        }
        if (!\in_array($align, self::ALIGNS, true)) {
            throw new \InvalidArgumentException(\sprintf('Column align must be one of: %s.', implode(', ', self::ALIGNS)));
        }
        if ($minWidth < 1) {
            throw new \InvalidArgumentException('A column is at least 1 px wide.');
        }
        if (null !== $defaultWidth && $defaultWidth < $minWidth) {
            throw new \InvalidArgumentException(\sprintf('The default width of column "%s" is below its minimum width.', $key));
        }
        if ('' === $sortKey) {
            throw new \InvalidArgumentException('A sort key cannot be empty; leave it null for a column the server does not sort by.');
        }
        $this->cell = \Closure::fromCallable($cell);
        $this->available = null === $available ? null : $available(...);
    }

    /**
     * @param array<string, mixed> $context
     */
    public function cell(mixed $row, array $context = []): mixed
    {
        return \call_user_func($this->cell, $row, $context);
    }

    /**
     * @param array<string, mixed> $context
     */
    public function available(array $context = []): bool
    {
        return null === $this->available || ($this->available)($context);
    }
}
