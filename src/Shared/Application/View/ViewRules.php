<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Shared\Application\View;

/**
 * The checks of what a saved view stores besides its filter: the sort and the
 * display. The display is checked for its shape only; the list columns read it
 * leniently (D-display-2), as a stored view may name a column a disabled module
 * took away.
 */
final class ViewRules
{
    /** As the list columns read them (Foresi\Ui\Contract\ListDisplay::DENSITIES). */
    public const array DENSITIES = ['comfortable', 'dense'];
    public const int MAX_SORT = 3;
    public const int MAX_COLUMNS = 40;
    /** As a list pins them (ListDefinition::MAX_PINNED). */
    public const int MAX_PINNED = ListDefinition::MAX_PINNED;
    private const string KEY = '/^[a-z][A-Za-z0-9_.]{0,63}$/';

    /**
     * @param list<mixed> $sort
     *
     * @return list<array{field: string, dir: string}>
     *
     * @throws InvalidFilter
     */
    public static function sort(array $sort): array
    {
        if (\count($sort) > self::MAX_SORT) {
            throw new InvalidFilter('sort', \sprintf('A view sorts by at most %d fields.', self::MAX_SORT));
        }
        $checked = [];
        foreach ($sort as $index => $item) {
            $path = \sprintf('sort[%d]', $index);
            if (!\is_array($item) || [] !== array_diff(array_keys($item), ['field', 'dir'])) {
                throw new InvalidFilter($path, 'A sort is an object of field and dir.');
            }
            if (!\is_string($item['field'] ?? null) || 1 !== preg_match(self::KEY, $item['field'])) {
                throw new InvalidFilter($path.'.field', 'A sort names a field such as "due_date".');
            }
            $dir = $item['dir'] ?? 'asc';
            if (!\in_array($dir, ['asc', 'desc'], true)) {
                throw new InvalidFilter($path.'.dir', 'The direction is asc or desc.');
            }
            $checked[] = ['field' => $item['field'], 'dir' => $dir];
        }

        return $checked;
    }

    /**
     * The display payload {columns: [{key, width?}], density?, group_by?, pinned?}, or null for
     * the list's defaults. What a list groups by is checked for its shape only; a list
     * that does not group by it shows its own grouping (D-display-2). The pinned fields are
     * the view's quick filters, in their order: none pins none, null keeps the list's own.
     *
     * @param list<mixed>|null $columns
     * @param list<mixed>|null $pinned
     *
     * @return array<string, mixed>|null
     *
     * @throws InvalidFilter
     */
    public static function display(?array $columns, ?string $density, ?string $groupBy = null, ?array $pinned = null): ?array
    {
        if (null !== $pinned) {
            if (\count($pinned) > self::MAX_PINNED) {
                throw new InvalidFilter('pinned', \sprintf('A view pins at most %d quick filters.', self::MAX_PINNED));
            }
            foreach ($pinned as $index => $key) {
                if (!\is_string($key) || 1 !== preg_match(self::KEY, $key)) {
                    throw new InvalidFilter(\sprintf('pinned[%d]', $index), 'A quick filter is named by the key of its field, such as "due_date".');
                }
            }
            $pinned = array_values(array_unique($pinned));
        }
        if (null !== $density && !\in_array($density, self::DENSITIES, true)) {
            throw new InvalidFilter('density', \sprintf('The density is one of %s.', implode(', ', self::DENSITIES)));
        }
        if (null !== $groupBy && 1 !== preg_match(self::KEY, $groupBy)) {
            throw new InvalidFilter('group_by', 'A grouping is named by its key, such as "person".');
        }
        $rest = (null === $density ? [] : ['density' => $density]) + (null === $groupBy ? [] : ['group_by' => $groupBy]) + (null === $pinned ? [] : ['pinned' => $pinned]);
        if (null === $columns) {
            return [] === $rest ? null : $rest;
        }
        if (\count($columns) > self::MAX_COLUMNS) {
            throw new InvalidFilter('columns', \sprintf('A view shows at most %d columns.', self::MAX_COLUMNS));
        }
        $checked = [];
        foreach ($columns as $index => $column) {
            $path = \sprintf('columns[%d]', $index);
            $column = \is_string($column) ? ['key' => $column] : $column;
            if (!\is_array($column) || [] !== array_diff(array_keys($column), ['key', 'width'])) {
                throw new InvalidFilter($path, 'A column is a key, or an object of key and width.');
            }
            if (!\is_string($column['key'] ?? null) || 1 !== preg_match(self::KEY, $column['key'])) {
                throw new InvalidFilter($path.'.key', 'A column is named by its key, such as "due_date".');
            }
            $width = $column['width'] ?? null;
            if (null !== $width && (!\is_int($width) || $width < 1 || $width > 2000)) {
                throw new InvalidFilter($path.'.width', 'A width is a whole number of px from 1 to 2000.');
            }
            $checked[] = null === $width ? ['key' => $column['key']] : ['key' => $column['key'], 'width' => $width];
        }

        return ['columns' => $checked] + $rest;
    }
}
