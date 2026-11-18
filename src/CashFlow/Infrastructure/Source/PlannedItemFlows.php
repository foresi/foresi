<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\CashFlow\Infrastructure\Source;

use Foresi\CashFlow\Application\CashFlowStore;
use Foresi\CashFlow\Contract\CashFlowSource;
use Foresi\CashFlow\Contract\ExpectedFlow;
use Foresi\CashFlow\Contract\FlowCertainty;
use Foresi\CashFlow\Contract\FlowSubject;
use Foresi\CashFlow\Domain\PlannedItem;
use Foresi\Shared\Domain\Schedule\Recurrence;
use Foresi\Shared\Domain\Schedule\RecurringSchedule;

/**
 * The planned items of Cash flow itself as a source of the forecast: every
 * occurrence of an included item from the first day to the last, but the
 * skipped ones. A planned occurrence that has passed is not overdue; it was a
 * plan, and what really happened is in the balance.
 */
final readonly class PlannedItemFlows implements CashFlowSource
{
    public const string KEY = 'cash-flow.planned_items';
    private const int CHUNK = 64;

    public function __construct(private CashFlowStore $store)
    {
    }

    public function key(): string
    {
        return self::KEY;
    }

    public function mayReadDetails(): bool
    {
        // The forecast itself asks for cashflow:read, which planned items are part of.
        return true;
    }

    public function flows(string $firmId, \DateTimeImmutable $from, \DateTimeImmutable $to, \DateTimeImmutable $today): iterable
    {
        $zone = $today->getTimezone();
        foreach ($this->store->plannedItemsOf($firmId) as $item) {
            if (!$item->isIncluded()) {
                continue;
            }
            foreach ($this->days($item, $from, $to, $zone) as $day) {
                if (!$item->isSkipped($day)) {
                    yield new ExpectedFlow(
                        self::KEY,
                        new FlowSubject('planned_item', $item->id()),
                        $item->direction(),
                        $item->amount(),
                        $day,
                        $item->category(),
                        FlowCertainty::Plan,
                        $item->name(),
                        counterpartyId: $item->counterparty()?->id(),
                    );
                }
            }
        }
    }

    /**
     * @return iterable<\DateTimeImmutable>
     */
    private function days(PlannedItem $item, \DateTimeImmutable $from, \DateTimeImmutable $to, \DateTimeZone $zone): iterable
    {
        $last = $to->format('Y-m-d');
        $recurrence = $item->recurrence($zone);
        if (!$recurrence instanceof Recurrence) {
            $day = PlannedItem::inZone($item->firstOn(), $zone);
            if ($day->format('Y-m-d') >= $from->format('Y-m-d') && $day->format('Y-m-d') <= $last) {
                yield $day;
            }

            return;
        }
        $cursor = $from;
        do {
            $days = RecurringSchedule::next($recurrence, 0, $cursor, self::CHUNK);
            foreach ($days as $day) {
                if ($day->format('Y-m-d') > $last) {
                    return;
                }
                yield $day;
                $cursor = $day->modify('+1 day');
            }
        } while (self::CHUNK === \count($days));
    }
}
