<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Ui\Column;

use Foresi\Shared\Application\View\ViewRules;
use Foresi\Ui\Contract\Attribute;
use Foresi\Ui\Contract\Column;
use Foresi\Ui\Contract\DataSource;
use Foresi\Ui\Contract\ListDisplay;
use Foresi\Ui\Contract\ResolvedColumn;
use Foresi\Ui\Format\AttributeCells;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * The columns of every list, one per attribute of its data source (DataSource, spec DS1), and
 * how one list shows them: the registry's defaults, or a view's display payload read leniently,
 * as a stored view may name a column a disabled module took away.
 *
 * The payload is {columns: [{key, width?}], group_by?}. Its order is the column order
 * and presence means visible; a width is in px and an absent one sizes the column to its content.
 * Reading it resolves an alias to its key (DS2), drops unknown and repeated keys and the columns
 * the context makes unavailable, and re-adds the locked columns it leaves out, first, in their
 * default order.
 */
final class ListColumnRegistry
{
    /** @var array<string, array{columns: list<Column>, keys: array<string, string>}> by list */
    private array $lists = [];

    /**
     * @param iterable<DataSource> $sources
     */
    public function __construct(
        #[AutowireIterator(DataSource::TAG)]
        private readonly iterable $sources,
        private readonly TranslatorInterface $translator,
        private readonly AttributeCells $cells,
    ) {
    }

    /**
     * Every column of a list, available or not, in the default order (by default position, then
     * in the order the sources declare them).
     *
     * @return list<Column>
     */
    public function columns(string $list): array
    {
        return $this->list($list)['columns'];
    }

    /**
     * The key of a column of the list named by its key or by an alias, such as "issueDate" for
     * "issue_date"; null for none.
     */
    public function key(string $list, string $name): ?string
    {
        return $this->list($list)['keys'][$name] ?? null;
    }

    /**
     * The attribute of the list named by its key or an alias, so a detail page shows its value
     * as the list does.
     *
     * @throws \InvalidArgumentException for an attribute the list does not have
     */
    public function attribute(string $list, string $name): Attribute
    {
        $key = $this->key($list, $name);
        foreach ($this->list($list)['columns'] as $column) {
            if ($column->key === $key) {
                return $column->attribute;
            }
        }

        throw new \InvalidArgumentException(\sprintf('The list "%s" has no attribute "%s".', $list, $name));
    }

    /**
     * A sort of the list, such as "-due_date": by a column the list's query sorts by, named by its
     * key or an alias (today's ?sort=dueDate), a minus for descending. Anything else gives the
     * fallback.
     */
    public function sort(string $list, string $sort, string $fallback): string
    {
        $descending = str_starts_with($sort, '-');
        $key = $this->key($list, substr($sort, $descending ? 1 : 0));
        foreach ($this->columns($list) as $column) {
            if ($column->key === $key && null !== $column->sortKey) {
                return ($descending ? '-' : '').$column->sortKey;
            }
        }

        return $fallback;
    }

    /**
     * @param array<string, mixed>         $context what the list's module knows of the screen, such as several firms or the trash
     * @param array<array-key, mixed>|null $display a view's display payload; null keeps the registry's defaults
     */
    public function display(string $list, array $context = [], ?array $display = null): ListDisplay
    {
        $available = array_filter($this->columns($list), static fn (Column $column): bool => $column->available($context));
        $byKey = array_column(array_map(static fn (Column $column): array => [$column->key, $column], $available), 1, 0);
        $wanted = null === $display ? null : ($display['columns'] ?? null);

        if (!\is_array($wanted)) {
            $columns = array_map(fn (Column $column): ResolvedColumn => $this->resolved($column, $column->defaultWidth), array_values(array_filter($available, static fn (Column $column): bool => $column->defaultVisible || $column->locked)));
        } else {
            $columns = [];
            foreach ($wanted as $entry) {
                $key = \is_array($entry) ? ($entry['key'] ?? null) : $entry;
                $key = \is_string($key) ? $this->key($list, $key) : null;
                if (null === $key || !isset($byKey[$key]) || isset($columns[$key])) {
                    continue;
                }
                $column = $byKey[$key];
                $width = \is_array($entry) && \is_int($entry['width'] ?? null) && $entry['width'] > 0 ? max($entry['width'], $column->minWidth) : null;
                $columns[$key] = $this->resolved($column, $width);
            }
            $locked = array_map(fn (Column $column): ResolvedColumn => $this->resolved($column, $column->defaultWidth), array_filter($available, static fn (Column $column): bool => $column->locked && !isset($columns[$column->key])));
            $columns = [...$locked, ...array_values($columns)];
        }

        $groupBy = $display['group_by'] ?? null;
        $layout = $display['layout'] ?? null;

        return new ListDisplay(
            $columns,
            $context,
            \is_string($groupBy) && '' !== $groupBy ? $groupBy : null,
            \in_array($layout, ViewRules::LAYOUTS, true) ? $layout : null,
            false !== ($display['weekends'] ?? null),
        );
    }

    /**
     * @return array{columns: list<Column>, keys: array<string, string>}
     */
    private function list(string $list): array
    {
        if (isset($this->lists[$list])) {
            return $this->lists[$list];
        }
        $columns = [];
        foreach ($this->sources as $source) {
            foreach ($source->attributes($list) as $attribute) {
                if (isset($columns[$attribute->key])) {
                    throw new \LogicException(\sprintf('The list "%s" has two attributes "%s".', $list, $attribute->key));
                }
                $columns[$attribute->key] = new Column($attribute, fn (mixed $row, array $context): mixed => $this->cells->cell($attribute, $row, $context));
            }
        }
        // usort is stable since PHP 8.0, so equal positions keep the declared order.
        usort($columns, static fn (Column $a, Column $b): int => $a->defaultPosition <=> $b->defaultPosition);
        $keys = [];
        foreach ($columns as $column) {
            $keys[$column->key] = $column->key;
        }
        foreach ($columns as $column) {
            foreach ($column->attribute->aliases as $alias) {
                if (isset($keys[$alias])) {
                    throw new \LogicException(\sprintf('The list "%s" names two attributes "%s".', $list, $alias));
                }
                $keys[$alias] = $column->key;
            }
        }

        return $this->lists[$list] = ['columns' => $columns, 'keys' => $keys];
    }

    private function resolved(Column $column, ?int $width): ResolvedColumn
    {
        return new ResolvedColumn($column, $this->translator->trans($column->label), $width);
    }
}
