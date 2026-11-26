<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Shared\Application\View;

use Symfony\Component\HttpFoundation\InputBag;

/**
 * Where one table of a page keeps its state in the page's address, when a page shows
 * several (docs/design/list-pages.md, rule 8): its view, filter, search, sort and
 * display. The page's first table keeps the bare keys every list has (view=, f=, q=,
 * sort=, cols=, group=, pins=, widths=, c[…]= of the builder), so links to a
 * page that had one table keep working; every other table keeps the same keys nested
 * under its slot's name, as reasons[cols]=…&reasons[widths]=…. A link or a form of one
 * table carries the others' state unchanged, as Notion keeps the views of each linked
 * database on a page apart.
 */
final readonly class ListSlot
{
    /** The keys of a list's own state, which no slot may be named after. */
    private const array RESERVED = ['view', 'tab', 'f', 'q', 'sort', 'page', 'c', 'conditions', 'cols', 'group', 'pins', 'widths'];

    /**
     * @param array<array-key, mixed> $page   the page's whole query
     * @param array<string>           $others the slots of the page's other tables, for the first
     */
    private function __construct(public string $name, private array $page, private array $others)
    {
    }

    /**
     * The page's first table, on the bare keys.
     *
     * @param array<array-key, mixed> $page      the page's whole query
     * @param string                  ...$others the slots of the page's other tables
     */
    public static function first(array $page, string ...$others): self
    {
        array_map(self::check(...), $others);

        return new self('', $page, $others);
    }

    /**
     * Another table of the page, under its slot.
     *
     * @param array<array-key, mixed> $page the page's whole query
     */
    public static function named(string $name, array $page): self
    {
        self::check($name);

        return new self($name, $page, []);
    }

    private function isFirst(): bool
    {
        return '' === $this->name;
    }

    /**
     * The table's own state, under the bare keys whichever slot it is in.
     *
     * @return array<array-key, mixed>
     */
    public function query(): array
    {
        if ($this->isFirst()) {
            return array_diff_key($this->page, array_flip($this->others));
        }
        $own = $this->page[$this->name] ?? [];

        return \is_array($own) ? $own : [];
    }

    /**
     * @return InputBag<bool|float|int|string|null>
     */
    public function input(): InputBag
    {
        // A list's state is under names; a bare number is no key of it.
        return new InputBag(array_filter($this->query(), \is_string(...), \ARRAY_FILTER_USE_KEY));
    }

    /**
     * The page's address with this table's own state replaced, the other tables' kept as
     * they are. An empty value stays, as it means the view's own (widths='' of Reset).
     *
     * @param array<array-key, mixed> $own the table's state, under the bare keys
     *
     * @return array<array-key, mixed>
     */
    public function with(array $own): array
    {
        $own = array_filter($own, static fn (mixed $value): bool => null !== $value);
        if ($this->isFirst()) {
            return $own + array_intersect_key($this->page, array_flip($this->others));
        }
        $page = $this->page;
        unset($page[$this->name]);

        return $page + ([] === $own ? [] : [$this->name => $own]);
    }

    /**
     * The name a form field of this table has: q stays q on the first table and is
     * reasons[q] on another; c[0][op] is reasons[c][0][op], cols[] reasons[cols][].
     */
    public function field(string $name): string
    {
        if ($this->isFirst()) {
            return $name;
        }
        $at = strpos($name, '[');

        return false === $at ? $this->name.'['.$name.']' : $this->name.'['.substr($name, 0, $at).']'.substr($name, $at);
    }

    /**
     * An id of the page that this table has more than once among the tables: the first
     * keeps it, another adds its slot.
     */
    public function id(string $id): string
    {
        return $this->isFirst() ? $id : $id.'-'.$this->name;
    }

    /**
     * The other tables' state as the hidden fields of a form of this table, so sending
     * it keeps them.
     *
     * @return array<string, string>
     */
    public function kept(): array
    {
        $others = $this->isFirst() ? array_intersect_key($this->page, array_flip($this->others)) : array_diff_key($this->page, array_flip([$this->name]));

        return self::flatten($others, '');
    }

    /**
     * @param array<array-key, mixed> $values
     *
     * @return array<string, string>
     */
    private static function flatten(array $values, string $prefix): array
    {
        $fields = [];
        foreach ($values as $key => $value) {
            $name = '' === $prefix ? "{$key}" : "{$prefix}[{$key}]";
            if (\is_array($value)) {
                $fields += self::flatten($value, $name);
            } elseif (\is_scalar($value)) {
                $fields[$name] = "{$value}";
            }
        }

        return $fields;
    }

    private static function check(string $name): void
    {
        if (1 !== preg_match('/^[a-z][a-z0-9_]{0,30}$/', $name) || \in_array($name, self::RESERVED, true)) {
            throw new \InvalidArgumentException(\sprintf('A list slot is a short lowercase name that is no key of a list\'s state, "%s" given.', $name));
        }
    }
}
