<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\Party\Application;

use Foresi\Instance\Application\InstanceClock;
use Foresi\Instance\Application\Schedule\RunScheduledJob;
use Foresi\Instance\Infrastructure\Schedule\RunScheduledJobHandler;
use Foresi\Party\Application\RefreshTodaysVatStatus;
use Foresi\Party\Domain\VatStatus;
use Foresi\Tests\Party\Support\VatStatusTestCase;

/**
 * The daily job moves the firm onto a dated VAT status on its day, by the
 * midnight of the instance's zone, Prague, not by UTC.
 */
final class RefreshTodaysVatStatusTest extends VatStatusTestCase
{
    public function testTodaysStatusChangesAtPragueMidnight(): void
    {
        $this->add('registered', 'month', '2027-01-01');
        $job = self::getContainer()->get(RefreshTodaysVatStatus::class);
        $zone = self::getContainer()->get(InstanceClock::class)->timezone();
        self::assertSame('Europe/Prague', $zone->getName());

        // 23:30 in Prague on 31 December.
        $this->clock->modify('2026-12-31 22:30:00');
        self::assertSame('2026-12-31', $job->period($this->clock->now(), $zone));
        $job->run(null);
        self::assertSame(VatStatus::NotRegistered, $this->storedStatus());

        // 00:30 in Prague on 1 January, still 31 December in UTC.
        $this->clock->modify('2026-12-31 23:30:00');
        self::assertSame('2027-01-01', $job->period($this->clock->now(), $zone));
        // Through the schedule, which knows the job by its name.
        self::getContainer()->get(RunScheduledJobHandler::class)(new RunScheduledJob(RefreshTodaysVatStatus::NAME, '2027-01-01'));
        self::assertSame(VatStatus::Registered, $this->storedStatus());
    }

    public function testTheJobIsNamedForTheSchedule(): void
    {
        self::assertSame('party-vat-status-refresh', self::getContainer()->get(RefreshTodaysVatStatus::class)->name());
    }
}
