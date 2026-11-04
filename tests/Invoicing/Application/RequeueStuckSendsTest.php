<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Tests\Invoicing\Application;

use App\Invoicing\Application\InvoiceSendQueue;
use App\Invoicing\Application\InvoiceSends;
use App\Invoicing\Application\RequeueStuckSends;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;

final class RequeueStuckSendsTest extends TestCase
{
    public function testAPeriodIsFiveMinutesOfUtcTimeInEveryZone(): void
    {
        $job = new RequeueStuckSends(self::createStub(InvoiceSends::class), self::createStub(InvoiceSendQueue::class), new MockClock());
        $prague = new \DateTimeZone('Europe/Prague');
        $tokyo = new \DateTimeZone('Asia/Tokyo');

        self::assertSame('2026-11-03T09:05', $job->period(new \DateTimeImmutable('2026-11-03 10:09:59', $prague), $prague));
        self::assertSame('2026-11-03T09:10', $job->period(new \DateTimeImmutable('2026-11-03 10:10:00', $prague), $prague));
        self::assertSame('2026-11-03T09:10', $job->period(new \DateTimeImmutable('2026-11-03 10:10:00', $prague), $tokyo), 'the tenant\'s zone changes nothing');
        self::assertSame('2026-11-03T23:00', $job->period(new \DateTimeImmutable('2026-11-03 23:04:00', new \DateTimeZone('UTC')), $tokyo));
    }
}
