<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Ui\Column;

use Foresi\Shared\Application\View\ViewRules;
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

    /**
     * @param array<string, mixed>                                    $context  the list's context, as ListColumnRegistry::display() takes it
     * @param array<array-key, mixed>|null                            $saved    the view's stored display payload; null for the registry's defaults
     * @param array<array-key, mixed>                                 $query    the list's query: cols as a comma separated string (or a list, as a form without JavaScript sends it), group, pins and widths
     * @param list<string>                                            $groups   what the list can group by, its default first; none for a list that does not group
     * @param array{fields: list<string>, default: list<string>}|null $pins     the fields a quick filter can be pinned for and those pinned by default
     * @param bool                                                    $calendar whether the list can be laid out as a calendar
     */
    public function read(string $list, array $context, ?array $saved, array $query, array $groups = [], ?array $pins = null, bool $calendar = false): WorkingState
    {
        // The view's quick filters, or the list's own while it keeps none (a shipped view).
        $basePins = null === $pins ? [] : (self::pins($saved['pinned'] ?? null, $pins['fields']) ?? self::pins($pins['default'], $pins['fields']) ?? []);
        $wantedPins = null === $pins || !\array_key_exists(self::PINNED, $query) ? null : (self::pins($query[self::PINNED], $pins['fields']) ?? []);
        $base = $this->registry->display($list, $context, $saved);
        $keys = $this->keys($query[self::COLUMNS] ?? null);
        $baseGroup = $this->group($base->groupBy, $groups);
        $wantedGroup = $query[self::GROUP] ?? null;
        $wantedGroup = \is_string($wantedGroup) && \in_array($wantedGroup, $groups, true) ? $wantedGroup : null;
        $wantedWidths = self::widths($query[self::WIDTHS] ?? null);
        $baseLayout = $this->layout($calendar, $base->layout);
        $wantedLayout = $calendar && \is_string($query[self::LAYOUT] ?? null) && \in_array($query[self::LAYOUT], ViewRules::LAYOUTS, true) ? $query[self::LAYOUT] : null;
        $baseWeekends = !$calendar || $base->weekends;
        $wantedWeekends = $calendar && \in_array($query[self::WEEKENDS] ?? null, ['0', '1'], true) ? '1' === $query[self::WEEKENDS] : null;

        if (null !== $keys) {
            // The column that names the row leads and never moves (the Settings sheet shows it so);
            // the registry drops a locked column the context does not offer.
            $locked = array_map(static fn (Column $column): string => $column->key, array_filter($this->registry->columns($list), static fn (Column $column): bool => $column->locked));
            $keys = [...$locked, ...array_diff($keys, $locked)];
        }
        $payload = $saved;
        if (null !== $keys || null !== $wantedGroup || null !== $wantedPins || null !== $wantedWidths || null !== $wantedLayout || null !== $wantedWeekends) {
            // The widths asked replace the view's all at once: a column they leave out sizes to its content.
            $widths = $wantedWidths ?? $this->widthsOf($base);
            $columns = null === $keys
                ? array_map(static fn (ResolvedColumn $column): array => array_filter(['key' => $column->key(), 'width' => $widths[$column->key()] ?? null], static fn (mixed $value): bool => null !== $value), $base->columns)
                : array_map(static fn (string $key): array => array_filter(['key' => $key, 'width' => $widths[$key] ?? null], static fn (mixed $value): bool => null !== $value), $keys);
            $group = $wantedGroup ?? $base->groupBy;
            $payload = ['columns' => $columns] + (null === $group ? [] : ['group_by' => $group]) + (null === $pins ? [] : ['pinned' => $wantedPins ?? $basePins])
                + (ViewRules::LAYOUT_CALENDAR === ($wantedLayout ?? $baseLayout) ? ['layout' => ViewRules::LAYOUT_CALENDAR] : [])
                + (false === ($wantedWeekends ?? $baseWeekends) ? ['weekends' => false] : []);
        }

        $display = $this->registry->display($list, $context, $payload);
        // Every column the context offers, translated, in the default order: the payload names them all.
        $all = $this->registry->display($list, $context, ['columns' => array_map(static fn (Column $column): string => $column->key, $this->registry->columns($list))])->columns;
        $shownGroup = $this->group($display->groupBy, $groups);
        $shownPins = $wantedPins ?? $basePins;
        $shownWidths = $this->widthsOf($display);
        $widthsChanged = $shownWidths !== array_intersect_key($this->widthsOf($base), array_flip($display->keys()));
        $shownLayout = $this->layout($calendar, $display->layout);
        $shownWeekends = !$calendar || $display->weekends;
        $modified = $display->keys() !== $base->keys() || $shownGroup !== $baseGroup || $shownPins !== $basePins || $widthsChanged || $shownLayout !== $baseLayout || $shownWeekends !== $baseWeekends;
        if (!$modified) {
            return new WorkingState($base, $saved, $all, false, [], $basePins);
        }

        $kept = array_filter([
            self::COLUMNS => $display->keys() === $base->keys() ? null : implode(',', $display->keys()),
            self::GROUP => $shownGroup === $baseGroup ? null : $shownGroup,
            // Empty pins none.
            self::PINNED => $shownPins === $basePins ? null : implode(',', $shownPins),
            self::WIDTHS => $widthsChanged ? self::encodeWidths($shownWidths) : null,
            self::LAYOUT => $shownLayout === $baseLayout ? null : $shownLayout,
            self::WEEKENDS => $shownWeekends === $baseWeekends ? null : ($shownWeekends ? '1' : '0'),
        ], static fn (?string $value): bool => null !== $value);

        return new WorkingState($display, $payload, $all, true, $kept, $shownPins);
    }

    /**
     * The quick filters a value names: a comma separated string (or a list, as a form without
     * JavaScript sends it), each a field the list can pin, once and at most MAX_PINNED; null when
     * it names none at all.
     *
     * @param list<string> $fields
     *
     * @return list<string>|null
     */
    public static function pins(mixed $value, array $fields): ?array
    {
        if (\is_string($value)) {
            $value = '' === $value ? [] : explode(',', $value);
        }
        if (!\is_array($value)) {
            return null;
        }
        $keys = [];
        foreach ($value as $key) {
            $key = \is_string($key) ? trim($key) : '';
            if (\in_array($key, $fields, true) && !\in_array($key, $keys, true)) {
                $keys[] = $key;
            }
        }

        return \array_slice($keys, 0, self::MAX_PINNED);
    }

    /**
     * The widths a value names: key:px pairs, comma separated, each px a whole number from 1 to
     * MAX_WIDTH (the registry raises one under a column's minimum to it); null when it names none,
     * as an empty value does, which is the view's own.
     *
     * @return array<string, int>|null
     */
    public static function widths(mixed $value): ?array
    {
        if (!\is_string($value)) {
            return null;
        }
        $widths = [];
        foreach (explode(',', $value) as $pair) {
            // A key, then a whole number of px from 1 to 9999; the first of a key counts.
            if (1 === preg_match('/^\s*([^\s:]+)\s*:\s*([1-9]\d{0,3})\s*$/', $pair, $match) && !isset($widths[$match[1]])) {
                $widths[$match[1]] = min((int) $match[2], self::MAX_WIDTH);
            }
        }

        return [] === $widths ? null : $widths;
    }

    /**
     * The widths as the address carries them.
     *
     * @param array<string, int> $widths
     */
    public static function encodeWidths(array $widths): string
    {
        return implode(',', array_map(static fn (string $key, int $px): string => $key.':'.$px, array_keys($widths), $widths));
    }

    /**
     * The widths a display gives its columns, by key; a column sized to its content is left out.
     *
     * @return array<string, int>
     */
    private function widthsOf(ListDisplay $display): array
    {
        $widths = [];
        foreach ($display->columns as $column) {
            if (null !== $column->width) {
                $widths[$column->key()] = $column->width;
            }
        }

        return $widths;
    }

    /**
     * How a display lays the rows out: a calendar only on a list that offers one.
     */
    private function layout(bool $calendar, ?string $layout): string
    {
        return $calendar && ViewRules::LAYOUT_CALENDAR === $layout ? ViewRules::LAYOUT_CALENDAR : ViewRules::LAYOUT_LIST;
    }

    /**
     * What a display groups by among the list's groupings: its default when it names none of them.
     *
     * @param list<string> $groups
     */
    private function group(?string $groupBy, array $groups): ?string
    {
        return \in_array($groupBy, $groups, true) ? $groupBy : ($groups[0] ?? null);
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
