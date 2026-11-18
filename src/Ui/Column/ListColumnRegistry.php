<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Ui\Column;

use Foresi\Ui\Contract\Column;
use Foresi\Ui\Contract\ListColumns;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * The columns of every list, filled by tag (ListColumns), and how one list shows them: the
 * registry's defaults, or a view's display payload read leniently, as a stored view may name a
 * column a disabled module took away.
 *
 * The payload is {columns: [{key, width?}], density?, group_by?}. Its order is the column order
 * and presence means visible; a width is in px and an absent one sizes the column to its content.
 * Reading it drops unknown and repeated keys and the columns the context makes unavailable, and
 * re-adds the locked columns it leaves out, first, in their default order.
 */
final readonly class ListColumnRegistry
{
    /**
     * @param iterable<ListColumns> $providers
     */
    public function __construct(
        #[AutowireIterator(ListColumns::TAG)]
        private iterable $providers,
        private TranslatorInterface $translator,
    ) {
    }

    /**
     * Every column of a list, available or not, in the default order (by default position, then
     * in the order the providers declare them).
     *
     * @return list<Column>
     */
    public function columns(string $list): array
    {
        $columns = [];
        foreach ($this->providers as $provider) {
            foreach ($provider->columns($list) as $column) {
                if (isset($columns[$column->key])) {
                    throw new \LogicException(\sprintf('The list "%s" has two columns "%s".', $list, $column->key));
                }
                $columns[$column->key] = $column;
            }
        }
        // usort is stable since PHP 8.0, so equal positions keep the declared order.
        usort($columns, static fn (Column $a, Column $b): int => $a->defaultPosition <=> $b->defaultPosition);

        return $columns;
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
                if (!\is_string($key) || !isset($byKey[$key]) || isset($columns[$key])) {
                    continue;
                }
                $column = $byKey[$key];
                $width = \is_array($entry) && \is_int($entry['width'] ?? null) && $entry['width'] > 0 ? max($entry['width'], $column->minWidth) : null;
                $columns[$key] = $this->resolved($column, $width);
            }
            $locked = array_map(fn (Column $column): ResolvedColumn => $this->resolved($column, $column->defaultWidth), array_filter($available, static fn (Column $column): bool => $column->locked && !isset($columns[$column->key])));
            $columns = [...$locked, ...array_values($columns)];
        }

        $density = $display['density'] ?? null;
        $groupBy = $display['group_by'] ?? null;

        return new ListDisplay(
            $columns,
            $context,
            \is_string($density) && \in_array($density, ListDisplay::DENSITIES, true) ? $density : null,
            \is_string($groupBy) && '' !== $groupBy ? $groupBy : null,
        );
    }

    private function resolved(Column $column, ?int $width): ResolvedColumn
    {
        return new ResolvedColumn($column, $this->translator->trans($column->label), $width);
    }
}
