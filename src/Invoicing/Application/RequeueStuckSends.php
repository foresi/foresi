<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Invoicing\Application;

use App\Tenancy\Application\Schedule\ScheduledJob;
use Psr\Clock\ClockInterface;

/**
 * Hands sends to the worker again whose message was lost: a process that died
 * after committing the send and before queueing it leaves it waiting for good.
 * Every five minutes, sends older than fifteen, a hundred at a time. A second
 * message for a send that turns out to be on its way is harmless: delivery
 * claims the send, and a sent one sends nothing.
 */
final readonly class RequeueStuckSends implements ScheduledJob
{
    public const string NAME = 'invoice-sends';
    private const string AGE = '-15 minutes';
    private const int LIMIT = 100;
    private const int PERIOD_MINUTES = 5;

    public function __construct(
        private InvoiceSends $sends,
        private InvoiceSendQueue $queue,
        private ClockInterface $clock,
    ) {
    }

    public function name(): string
    {
        return self::NAME;
    }

    public function period(\DateTimeImmutable $now): string
    {
        $utc = $now->setTimezone(new \DateTimeZone('UTC'));
        $minute = intdiv((int) $utc->format('i'), self::PERIOD_MINUTES) * self::PERIOD_MINUTES;

        return $utc->format('Y-m-d\TH:').\sprintf('%02d', $minute);
    }

    public function run(?string $lastPeriod): void
    {
        $before = \DateTimeImmutable::createFromInterface($this->clock->now())->modify(self::AGE);
        foreach ($this->sends->stuck($before, self::LIMIT) as $id) {
            $this->queue->deliver($id);
        }
    }
}
