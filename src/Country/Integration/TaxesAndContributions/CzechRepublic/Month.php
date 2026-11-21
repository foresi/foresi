<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Country\Integration\TaxesAndContributions\CzechRepublic;

/**
 * A month as the statuses on its first day make it: activity counts by whole
 * months. The sickness base is the monthly base the owner chose, in minor units.
 */
final readonly class Month
{
    public const string MAIN = 'main';
    public const string SECONDARY = 'secondary';
    public const string NONE = 'none';

    public function __construct(
        public \DateTimeImmutable $first,
        public string $activity,
        public bool $healthMinimumExempt,
        public bool $sicknessInsurance,
        public ?int $sicknessBase,
    ) {
    }

    public function isActive(): bool
    {
        return self::NONE !== $this->activity;
    }

    public function key(): string
    {
        return $this->first->format('Y-m');
    }
}
