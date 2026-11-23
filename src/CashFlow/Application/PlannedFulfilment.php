<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\CashFlow\Application;

use Foresi\CashFlow\Contract\ActualFlow;
use Foresi\CashFlow\Domain\MatchRule;
use Foresi\CashFlow\Domain\PlannedItem;
use Foresi\Shared\Domain\Money;
use Foresi\Shared\Domain\Schedule\Recurrence;
use Foresi\Shared\Domain\Schedule\RecurrenceUnit;

/**
 * Which planned occurrences the facts already replace (C13, C16).
 *
 * The period of an occurrence is the cycle of its item around it: the calendar
 * unit of the schedule holding the day, with the units before it when the item
 * repeats every few of them. A monthly item has the calendar month, one every
 * three months has the three calendar months ending with the occurrence's, a
 * weekly one has the ISO week. A one-off item has the days from its creation to
 * its day.
 *
 * An occurrence is fulfilled when, in its period and in its direction:
 *  - a document of the item's counterparty sits, for a recurring item (C13);
 *  - a bank movement meets the item's match rule: the counterparty account and
 *    the variable symbol it names, and the amount within the tolerance (C16).
 */
final class PlannedFulfilment
{
    /**
     * The first and last day of the period of an occurrence, in the zone of $day.
     *
     * @return array{\DateTimeImmutable, \DateTimeImmutable}
     */
    public static function period(PlannedItem $item, \DateTimeImmutable $day): array
    {
        $zone = $day->getTimezone();
        $recurrence = $item->recurrence($zone);
        $end = new \DateTimeImmutable($day->format('Y-m-d'), $zone);
        if (!$recurrence instanceof Recurrence) {
            $created = new \DateTimeImmutable($item->createdAt()->setTimezone($zone)->format('Y-m-d'), $zone);

            return [min($created, $end), $end];
        }
        $back = $recurrence->every() - 1;

        return match ($recurrence->unit()) {
            RecurrenceUnit::Day => [$end->modify(\sprintf('-%d days', $back)), $end],
            RecurrenceUnit::Week => [$end->modify('monday this week')->modify(\sprintf('-%d weeks', $back)), $end->modify('sunday this week')],
            RecurrenceUnit::Month => [$end->modify('first day of this month')->modify(\sprintf('-%d months', $back)), $end->modify('last day of this month')],
            RecurrenceUnit::Year => [new \DateTimeImmutable(\sprintf('%d-01-01', (int) $end->format('Y') - $back), $zone), new \DateTimeImmutable($end->format('Y').'-12-31', $zone)],
        };
    }

    /**
     * @param list<ActualFlow> $actuals
     * @param Money|null       $expected what the occurrence moves, with the VAT on top when it has it; the item's amount when null
     */
    public static function isFulfilled(PlannedItem $item, \DateTimeImmutable $day, array $actuals, ?Money $expected = null): bool
    {
        $expected ??= $item->amount();
        [$first, $last] = self::period($item, $day);
        [$first, $last] = [$first->format('Y-m-d'), $last->format('Y-m-d')];
        $counterparty = $item->isRecurring() ? $item->counterparty()?->id() : null;
        $rule = $item->matchRule();
        foreach ($actuals as $actual) {
            $on = $actual->on->format('Y-m-d');
            if ($actual->direction !== $item->direction() || $on < $first || $on > $last) {
                continue;
            }
            if (null !== $counterparty && $actual->counterpartyId === $counterparty) {
                return true;
            }
            if (self::meets($rule, $expected, $actual)) {
                return true;
            }
        }

        return false;
    }

    private static function meets(MatchRule $rule, Money $expected, ActualFlow $actual): bool
    {
        if (!$actual->meets($rule->counterpartyAccount(), $rule->variableSymbol()) || $actual->amount->currency() !== $expected->currency()) {
            return false;
        }

        return abs($actual->amount->minor() - $expected->minor()) <= ($rule->toleranceMinor() ?? 0);
    }
}
