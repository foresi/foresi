<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Work\Application;

use App\Tenancy\Application\Schedule\ScheduledJob;
use Psr\Clock\ClockInterface;

/**
 * Sends the daily digests of notifications once an hour, so each person gets
 * theirs at the hour they chose in the organisation's zone. The period is the
 * hour in UTC, which never repeats when the clocks go back; a missed hour is
 * made up by the next, as a digest due earlier today still goes out.
 */
final readonly class SendNotificationDigestsHourly implements ScheduledJob
{
    public const string NAME = 'notification-digest';

    public function __construct(private NotificationMailing $mailing, private ClockInterface $clock)
    {
    }

    public function name(): string
    {
        return self::NAME;
    }

    public function period(\DateTimeImmutable $now, \DateTimeZone $zone): string
    {
        return $now->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\TH');
    }

    public function run(?string $lastPeriod): void
    {
        $this->mailing->sendDigests(\DateTimeImmutable::createFromInterface($this->clock->now()));
    }
}
