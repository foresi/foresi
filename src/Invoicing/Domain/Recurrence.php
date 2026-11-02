<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Invoicing\Domain;

/**
 * How a recurring invoice repeats: from its first issue date, every N days,
 * weeks, months or years, until its end rule says stop. Dates are calendar
 * days at midnight in the first issue date's zone.
 */
final readonly class Recurrence
{
    public const int MAX_EVERY = 99;
    public const int MAX_END_AFTER = 999;

    private \DateTimeImmutable $firstIssueOn;
    private ?\DateTimeImmutable $endOn;

    public function __construct(
        \DateTimeImmutable $firstIssueOn,
        private RecurrenceUnit $unit,
        private int $every,
        private bool $onLastDayOfMonth,
        private RecurrenceEnd $end,
        ?\DateTimeImmutable $endOn,
        private ?int $endAfter,
    ) {
        if ($every < 1 || $every > self::MAX_EVERY) {
            throw new InvalidRecurrence(\sprintf('A recurrence repeats every 1 to %d units, not %d.', self::MAX_EVERY, $every));
        }
        if ($onLastDayOfMonth && \in_array($unit, [RecurrenceUnit::Day, RecurrenceUnit::Week], true)) {
            throw new InvalidRecurrence('The last day of the month applies only to monthly and yearly recurrence.');
        }

        $zone = $firstIssueOn->getTimezone();
        $this->firstIssueOn = $this->day($zone, $firstIssueOn);
        $this->endOn = $endOn instanceof \DateTimeImmutable ? $this->day($zone, $endOn) : null;

        match ($end) {
            RecurrenceEnd::Never => $this->expectNoEndValue(),
            RecurrenceEnd::OnDate => $this->expectEndOn(),
            RecurrenceEnd::AfterCount => $this->expectEndAfter(),
        };
    }

    public function firstIssueOn(): \DateTimeImmutable
    {
        return $this->firstIssueOn;
    }

    public function unit(): RecurrenceUnit
    {
        return $this->unit;
    }

    public function every(): int
    {
        return $this->every;
    }

    public function onLastDayOfMonth(): bool
    {
        return $this->onLastDayOfMonth;
    }

    public function end(): RecurrenceEnd
    {
        return $this->end;
    }

    public function endOn(): ?\DateTimeImmutable
    {
        return $this->endOn;
    }

    public function endAfter(): ?int
    {
        return $this->endAfter;
    }

    /**
     * The calendar day of $date, whatever its own zone, at midnight in $zone.
     */
    private function day(\DateTimeZone $zone, \DateTimeImmutable $date): \DateTimeImmutable
    {
        return RecurringSchedule::calendarDay($zone, (int) $date->format('Y'), (int) $date->format('n'), (int) $date->format('j'));
    }

    private function expectNoEndValue(): void
    {
        if ($this->endOn instanceof \DateTimeImmutable || null !== $this->endAfter) {
            throw new InvalidRecurrence('A recurrence that never ends has no end date or count.');
        }
    }

    private function expectEndOn(): void
    {
        if (!$this->endOn instanceof \DateTimeImmutable || null !== $this->endAfter) {
            throw new InvalidRecurrence('A recurrence that ends on a date needs that date and no count.');
        }
        // On the last day of the month, the first occurrence can fall after the first issue date.
        if ($this->endOn < RecurringSchedule::occurrence($this, 0)) {
            throw new InvalidRecurrence('A recurrence cannot end before its first occurrence, as it would never issue.');
        }
    }

    private function expectEndAfter(): void
    {
        if (null === $this->endAfter || $this->endOn instanceof \DateTimeImmutable) {
            throw new InvalidRecurrence('A recurrence that ends after a count needs that count and no date.');
        }
        if ($this->endAfter < 1 || $this->endAfter > self::MAX_END_AFTER) {
            throw new InvalidRecurrence(\sprintf('A recurrence ends after 1 to %d invoices, not %d.', self::MAX_END_AFTER, $this->endAfter));
        }
    }
}
