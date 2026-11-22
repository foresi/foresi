<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Party\Application;

use Foresi\Instance\Application\Schedule\DailyPeriod;
use Foresi\Instance\Application\Schedule\ScheduledJob;

/**
 * Once a day from midnight in the instance's zone, the firm takes the VAT
 * status that holds that day, so a dated status begins on its day without
 * anyone changing the statuses.
 */
final readonly class RefreshTodaysVatStatus implements ScheduledJob
{
    public const string NAME = 'party-vat-status-refresh';
    public const int START_HOUR = 0;

    public function __construct(private VatStatusWrites $writes)
    {
    }

    public function name(): string
    {
        return self::NAME;
    }

    public function period(\DateTimeImmutable $now, \DateTimeZone $zone): string
    {
        return DailyPeriod::of($now, $zone, self::START_HOUR);
    }

    public function run(?string $lastPeriod): void
    {
        $this->writes->refresh();
    }
}
