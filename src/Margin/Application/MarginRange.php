<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Margin\Application;

/**
 * The months a margin table covers: whole calendar months in the zone of the
 * instance, the first and the last included, at most MAX_MONTHS of them.
 */
final readonly class MarginRange
{
    public const int MAX_MONTHS = 24;

    /**
     * @param non-empty-list<string> $months YYYY-MM, the earliest first
     */
    public function __construct(
        public \DateTimeImmutable $from,
        public \DateTimeImmutable $to,
        public array $months,
    ) {
    }

    /**
     * The first day of the first month, at midnight in the zone of the instance.
     */
    public function firstDay(): \DateTimeImmutable
    {
        return $this->from;
    }

    /**
     * The last day of the last month, at midnight in the zone of the instance.
     */
    public function lastDay(): \DateTimeImmutable
    {
        return $this->to;
    }

    public function fromMonth(): string
    {
        return array_first($this->months);
    }

    public function toMonth(): string
    {
        return array_last($this->months);
    }

    public function contains(string $month): bool
    {
        return \in_array($month, $this->months, true);
    }
}
