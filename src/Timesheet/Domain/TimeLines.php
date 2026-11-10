<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Timesheet\Domain;

/**
 * The lines an invoice of time gets: entries grouped as chosen, each rounded
 * before its line sums them. A line holds one rate in one currency, so a group
 * whose entries differ in either splits into a line per rate. Lines go by
 * project, then by what names them (time without a task first), then by rate;
 * a line of one entry goes by its day. Their keys follow that order.
 */
final class TimeLines
{
    /**
     * @param list<BillableTime> $times
     *
     * @return list<TimeLine>
     */
    public static function of(array $times, LineGrouping $grouping, TimeRounding $rounding): array
    {
        /** @var array<string, array{order: list<int|string>, names: list<string>, entries: list<BillableTime>}> $groups */
        $groups = [];
        foreach ($times as $time) {
            [$order, $names] = self::group($time, $grouping);
            // What a line sorts by also tells it apart: it ends with the ids of what groups it.
            $order = [...$order, $time->rate->currency()->value, $time->rate->minor()];
            $key = json_encode($order, \JSON_THROW_ON_ERROR);
            $groups[$key] ??= ['order' => $order, 'names' => $names, 'entries' => []];
            $groups[$key]['entries'][] = $time;
        }
        uasort($groups, static fn (array $a, array $b): int => self::compare($a['order'], $b['order']));

        $lines = [];
        foreach (array_values($groups) as $index => $group) {
            $entries = array_map(static fn (BillableTime $time): BilledTime => new BilledTime($time, $rounding->minutes($time->minutes)), $group['entries']);
            $first = $group['entries'][0];
            $lines[] = new TimeLine('line-'.($index + 1), $group['names'], $first->rate, $entries, LineGrouping::Entry === $grouping ? $first : null);
        }

        return $lines;
    }

    /**
     * How the line of the entry sorts, ending with the ids of what groups it,
     * and what names it.
     *
     * @return array{list<int|string>, list<string>}
     */
    private static function group(BillableTime $time, LineGrouping $grouping): array
    {
        $project = [$time->projectName, $time->projectId];
        $named = null === $time->taskTitle ? [$time->projectName] : [$time->projectName, $time->taskTitle];

        return match ($grouping) {
            LineGrouping::Project => [$project, [$time->projectName]],
            // Time without a task has neither title nor id, so it sorts first.
            LineGrouping::Task => [[...$project, $time->taskTitle ?? '', $time->taskId ?? ''], $named],
            LineGrouping::Person => [[...$project, $time->personName, $time->membershipId], [$time->projectName, $time->personName]],
            LineGrouping::Entry => [[...$project, $time->workedOn->format('Y-m-d'), $time->entryId], $named],
        };
    }

    /**
     * @param list<int|string> $a
     * @param list<int|string> $b
     */
    private static function compare(array $a, array $b): int
    {
        foreach ($a as $index => $value) {
            $other = $b[$index] ?? '';
            // Natural order puts the rates, which are never negative, by their amount.
            $order = strnatcasecmp((string) $value, (string) $other);
            if (0 !== $order) {
                return $order;
            }
        }

        return 0;
    }
}
