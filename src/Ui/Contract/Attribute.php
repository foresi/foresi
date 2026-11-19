<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Ui\Contract;

use Foresi\Shared\Domain\View\AttributeType;

/**
 * One attribute of a data source (DataSource, spec DS1): what a list can show of each of its
 * rows, such as the due date of an invoice. The list's columns are derived from the attributes:
 * the screens format the value by its type (DS4), unless the attribute renders a cell of its own,
 * such as a status badge.
 *
 * The key is stable and snake_case, a dot joining a relation to an attribute of its target
 * (DS2): a saved view keeps its columns by key, never by position. Aliases are older keys that
 * still name the attribute, such as "dueDate", so a stored view or a link keeps working; it is
 * written back under the key.
 *
 * Sort is the DQL expression the list's query orders by; an attribute without one is not
 * sortable (DS10). An attribute is shown by default only when defaultVisible; the rest is what
 * a person can add (DS5). A locked attribute, the one that names the row, cannot be hidden.
 */
final readonly class Attribute
{
    public const int MIN_WIDTH = 64;
    public const string KEY = '/^[a-z][a-z0-9_]*(\.[a-z][a-z0-9_]*)*$/';
    /** The column keys before the catalogue, kept as aliases (camelCase). */
    private const string ALIAS = '/^[a-z][A-Za-z0-9_.]*$/';

    /** The value of one row; each list types the row it passes, so the attribute cannot type it here. */
    private \Closure $value;

    private ?\Closure $cell;

    /** @var (\Closure(array<string, mixed>): bool)|null */
    private ?\Closure $available;

    /**
     * @param string                                      $label        the translation key of its name
     * @param callable                                    $value        the raw value of one row, given the row as the list passes it and the list's context:
     *                                                                  a string, int, bool, Money, date, backed enum or null; a datetime in the zone it is shown in
     * @param string|null                                 $sort         the DQL expression the list's query sorts by; null when it cannot
     * @param int|null                                    $defaultWidth in px; null sizes the column to its content
     * @param array<string, string>                       $options      of an enum: the translation key of each value's label, by value
     * @param callable|null                               $cell         a cell of its own, given the row and the context, as Ui:DataTable reads it; null formats the value by its type
     * @param list<string>                                $aliases      older keys that name the attribute too
     * @param bool                                        $phone        false leaves the column out on a phone
     * @param bool                                        $wide         true shows the column on a wide screen only
     * @param bool                                        $truncate     true lets the column give way, cut with an ellipsis
     * @param (callable(array<string, mixed>): bool)|null $available    whether the list's context offers the attribute at all
     */
    public function __construct(
        public string $key,
        public string $label,
        public AttributeType $type,
        callable $value,
        public ?string $sort = null,
        public bool $defaultVisible = false,
        public int $defaultPosition = 0,
        public ?int $defaultWidth = null,
        public int $minWidth = self::MIN_WIDTH,
        public array $options = [],
        ?callable $cell = null,
        public array $aliases = [],
        public bool $locked = false,
        public bool $phone = true,
        public bool $wide = false,
        public bool $truncate = false,
        ?callable $available = null,
    ) {
        if (1 !== preg_match(self::KEY, $key)) {
            throw new \InvalidArgumentException(\sprintf('An attribute key is a stable snake_case identifier such as "due_date", not "%s".', $key));
        }
        foreach ($aliases as $alias) {
            if (1 !== preg_match(self::ALIAS, $alias) || $alias === $key) {
                throw new \InvalidArgumentException(\sprintf('An alias of attribute "%s" is an older key such as "dueDate".', $key));
            }
        }
        if ('' === $label) {
            throw new \InvalidArgumentException('An attribute needs a label.');
        }
        if ($minWidth < 1) {
            throw new \InvalidArgumentException('A column is at least 1 px wide.');
        }
        if (null !== $defaultWidth && $defaultWidth < $minWidth) {
            throw new \InvalidArgumentException(\sprintf('The default width of attribute "%s" is below its minimum width.', $key));
        }
        if ('' === $sort) {
            throw new \InvalidArgumentException('A sort expression cannot be empty; leave it null for an attribute the server does not sort by.');
        }
        if ((AttributeType::Enum === $type) !== ([] !== $options)) {
            throw new \InvalidArgumentException(\sprintf('Attribute "%s": an enum lists its values with their labels, and only an enum does.', $key));
        }
        $this->value = \Closure::fromCallable($value);
        $this->cell = null === $cell ? null : \Closure::fromCallable($cell);
        $this->available = null === $available ? null : $available(...);
    }

    /**
     * @param array<string, mixed> $context
     */
    public function value(mixed $row, array $context = []): mixed
    {
        return \call_user_func($this->value, $row, $context);
    }

    public function hasCell(): bool
    {
        return $this->cell instanceof \Closure;
    }

    /**
     * The cell of its own, if it has one (hasCell()); null otherwise.
     *
     * @param array<string, mixed> $context
     */
    public function cell(mixed $row, array $context = []): mixed
    {
        return $this->cell instanceof \Closure ? \call_user_func($this->cell, $row, $context) : null;
    }

    /**
     * @param array<string, mixed> $context
     */
    public function available(array $context = []): bool
    {
        return null === $this->available || ($this->available)($context);
    }

    public function sortable(): bool
    {
        return null !== $this->sort;
    }
}
