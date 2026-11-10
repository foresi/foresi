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
            [$group, $order, $names] = self::group($time, $grouping);
            $key = $group.'|'.$time->rate->currency()->value.'|'.$time->rate->minor();
            $groups[$key] ??= ['order' => [...$order, $time->rate->currency()->value, $time->rate->minor()], 'names' => $names, 'entries' => []];
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
     * What groups the entry, how its line sorts and what names it.
     *
     * @return array{string, list<int|string>, list<string>}
     */
    private static function group(BillableTime $time, LineGrouping $grouping): array
    {
        $project = [$time->projectName, $time->projectId];

        return match ($grouping) {
            LineGrouping::Project => [$time->projectId, $project, [$time->projectName]],
            LineGrouping::Task => null === $time->taskId
                ? [$time->projectId.'/', [...$project, 0, '', ''], [$time->projectName]]
                : [$time->projectId.'/'.$time->taskId, [...$project, 1, (string) $time->taskTitle, $time->taskId], [$time->projectName, (string) $time->taskTitle]],
            LineGrouping::Person => [$time->projectId.'/'.$time->membershipId, [...$project, $time->personName, $time->membershipId], [$time->projectName, $time->personName]],
            LineGrouping::Entry => [$time->entryId, [...$project, $time->workedOn->format('Y-m-d'), $time->entryId], null === $time->taskTitle ? [$time->projectName] : [$time->projectName, $time->taskTitle]],
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
            $order = \is_int($value) && \is_int($other) ? $value <=> $other : strnatcasecmp((string) $value, (string) $other);
            if (0 !== $order) {
                return $order;
            }
        }

        return 0;
    }
}
