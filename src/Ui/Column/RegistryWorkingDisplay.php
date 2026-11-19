<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Ui\Column;

use Foresi\Ui\Contract\Column;
use Foresi\Ui\Contract\ListDisplay;
use Foresi\Ui\Contract\ResolvedColumn;
use Foresi\Ui\Contract\WorkingDisplay;
use Foresi\Ui\Contract\WorkingState;

/**
 * The working display read through the list column registry.
 */
final readonly class RegistryWorkingDisplay implements WorkingDisplay
{
    private const int MAX_COLUMNS = 100;

    public function __construct(private ListColumnRegistry $registry)
    {
    }

    public function read(string $list, array $context, ?array $saved, array $query, string $density = ListDisplay::COMFORTABLE): WorkingState
    {
        $base = $this->registry->display($list, $context, $saved);
        $baseDensity = $base->density ?? $density;
        $keys = $this->keys($query[self::COLUMNS] ?? null);
        $wanted = $query[self::DENSITY] ?? null;
        $wanted = \is_string($wanted) && \in_array($wanted, ListDisplay::DENSITIES, true) ? $wanted : null;

        if (null !== $keys) {
            // The column that names the row leads and never moves (the Columns sheet shows it so);
            // the registry drops a locked column the context does not offer.
            $locked = array_map(static fn (Column $column): string => $column->key, array_filter($this->registry->columns($list), static fn (Column $column): bool => $column->locked));
            $keys = [...$locked, ...array_diff($keys, $locked)];
        }
        $payload = $saved;
        if (null !== $keys || null !== $wanted) {
            $widths = [];
            foreach ($base->columns as $column) {
                $widths[$column->key()] = $column->width;
            }
            $columns = null === $keys
                ? array_map(static fn (ResolvedColumn $column): array => array_filter(['key' => $column->key(), 'width' => $column->width], static fn (mixed $value): bool => null !== $value), $base->columns)
                : array_map(static fn (string $key): array => array_filter(['key' => $key, 'width' => $widths[$key] ?? null], static fn (mixed $value): bool => null !== $value), $keys);
            $payload = ['columns' => $columns, 'density' => $wanted ?? $baseDensity] + (null === $base->groupBy ? [] : ['group_by' => $base->groupBy]);
        }

        $display = $this->registry->display($list, $context, $payload);
        // Every column the context offers, translated, in the default order: the payload names them all.
        $all = $this->registry->display($list, $context, ['columns' => array_map(static fn (Column $column): string => $column->key, $this->registry->columns($list))])->columns;
        $shownDensity = $display->density ?? $density;
        $modified = $display->keys() !== $base->keys() || $shownDensity !== $baseDensity;
        if (!$modified) {
            return new WorkingState($base, $saved, $all, $baseDensity, false, []);
        }

        $kept = array_filter([
            self::COLUMNS => $display->keys() === $base->keys() ? null : implode(',', $display->keys()),
            self::DENSITY => $shownDensity === $baseDensity ? null : $shownDensity,
        ], static fn (?string $value): bool => null !== $value);

        return new WorkingState($display, $payload, $all, $shownDensity, true, $kept);
    }

    /**
     * @return list<string>|null null when the query names no columns
     */
    private function keys(mixed $value): ?array
    {
        if (\is_string($value)) {
            $value = '' === $value ? [] : explode(',', $value);
        }
        if (!\is_array($value)) {
            return null;
        }
        // An older key stays as it is: the registry's display names its column under the key.
        $keys = array_unique(array_filter(array_map(static fn (mixed $key): string => \is_string($key) ? trim($key) : '', $value), static fn (string $key): bool => '' !== $key));

        return array_values(\array_slice($keys, 0, self::MAX_COLUMNS));
    }
}
