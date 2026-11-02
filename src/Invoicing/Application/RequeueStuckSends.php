<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Invoicing\Application;

use App\Tenancy\Application\Schedule\ScheduledJob;
use Psr\Clock\ClockInterface;

/**
 * Hands sends to the worker again that no message will come back to: a process
 * that died after committing the send and before queueing it, a worker that died
 * holding the claim, or retries that ran out after the server took the mail and
 * the invoice was not yet marked. Every five minutes, a hundred at a time, each
 * at most once in fifteen minutes. A message for a send that turns out to be on
 * its way is harmless: delivery waits for the claim, and a mail the server took
 * is never sent again.
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

    /**
     * Five minutes of UTC, the same in every zone: sends wait for no tenant's day.
     */
    public function period(\DateTimeImmutable $now, \DateTimeZone $zone): string
    {
        $utc = $now->setTimezone(new \DateTimeZone('UTC'));
        $minute = intdiv((int) $utc->format('i'), self::PERIOD_MINUTES) * self::PERIOD_MINUTES;

        return $utc->format('Y-m-d\TH:').\sprintf('%02d', $minute);
    }

    public function run(?string $lastPeriod): void
    {
        $now = \DateTimeImmutable::createFromInterface($this->clock->now());
        $ids = $this->sends->stuck($now->modify(self::AGE), self::LIMIT);
        $this->sends->markRequeued($ids, $now);
        foreach ($ids as $id) {
            $this->queue->deliver($id);
        }
    }
}
