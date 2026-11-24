<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Margin\Application;

use Foresi\Instance\Application\InstanceClock;
use Foresi\Margin\Domain\MarginRefused;

/**
 * Reads the months a person asks for, for the table and the cell alike (the
 * API, the tool and the screen all come here). A month is YYYY-MM, a calendar
 * month in the zone of the instance. When a month is left out the range is the
 * last three months ending with the current one: a missing "from" is two
 * months before "to", a missing "to" is the current month.
 */
final readonly class MarginRanges
{
    private const string MONTH = '/^(\d{4})-(0[1-9]|1[0-2])$/';
    private const int DEFAULT_MONTHS = 3;

    public function __construct(private InstanceClock $clock)
    {
    }

    /**
     * @throws MarginRefused invalid_month, range_reversed or range_too_long
     */
    public function range(?string $from = null, ?string $to = null): MarginRange
    {
        $last = null === $to ? $this->currentMonth() : $this->month($to, 'to');
        $first = null === $from ? $last->modify('-'.(self::DEFAULT_MONTHS - 1).' months') : $this->month($from, 'from');
        if ($first > $last) {
            throw MarginRefused::invalid(MarginRefused::RANGE_REVERSED, 'from', \sprintf('The range starts at %s, after it ends at %s.', $first->format('Y-m'), $last->format('Y-m')));
        }
        // Counted, not listed, so an absurd range is refused before anything is built from it.
        $count = ((int) $last->format('Y') - (int) $first->format('Y')) * 12 + (int) $last->format('n') - (int) $first->format('n') + 1;
        if ($count > MarginRange::MAX_MONTHS) {
            throw MarginRefused::invalid(MarginRefused::RANGE_TOO_LONG, 'to', \sprintf('The range covers %d months, at most %d are shown at once.', $count, MarginRange::MAX_MONTHS));
        }
        $months = [$first->format('Y-m')];
        for ($month = $first->modify('+1 month'); $month <= $last; $month = $month->modify('+1 month')) {
            $months[] = $month->format('Y-m');
        }

        return new MarginRange($first, $last->modify('last day of this month'), $months);
    }

    /**
     * The first day of one month, at midnight in the zone of the instance.
     *
     * @throws MarginRefused invalid_month
     */
    public function month(string $month, string $field = 'month'): \DateTimeImmutable
    {
        if (1 !== preg_match(self::MONTH, $month)) {
            throw MarginRefused::invalid(MarginRefused::INVALID_MONTH, $field, 'A month is written YYYY-MM, for example 2026-11.');
        }

        return new \DateTimeImmutable($month.'-01 00:00:00', $this->clock->timezone());
    }

    private function currentMonth(): \DateTimeImmutable
    {
        return $this->clock->today()->modify('first day of this month');
    }
}
