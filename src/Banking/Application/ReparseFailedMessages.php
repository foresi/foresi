<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Banking\Application;

use App\Tenancy\Application\Schedule\ScheduledJob;
use Psr\Log\LoggerInterface;

/**
 * Reads again, every hour, the messages a parser of today may read better than
 * the one that tried: failed ones whose parser's version went up, and ones that
 * no parser knew under another set of parsers. A raw file that changed, or a
 * rejected message, is not tried again: no parser changes that. Each message is
 * processed on its own, so one that throws does not hold up the others; the
 * queue's own retries are not involved.
 */
final readonly class ReparseFailedMessages implements ScheduledJob
{
    public const string NAME = 'bank-reparse';
    private const int LIMIT = 100;

    public function __construct(
        private InboundMessages $messages,
        private BankMessageParsers $parsers,
        private ProcessMessage $process,
        private LoggerInterface $logger,
    ) {
    }

    public function name(): string
    {
        return self::NAME;
    }

    /**
     * An hour of UTC, the same in every zone: a parser's update waits for no tenant's day.
     */
    public function period(\DateTimeImmutable $now, \DateTimeZone $zone): string
    {
        return $now->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\TH');
    }

    public function run(?string $lastPeriod): void
    {
        foreach ($this->messages->due($this->parsers->versions(), $this->parsers->signature(), self::LIMIT) as $id) {
            try {
                $this->process->process($id);
            } catch (\Throwable $failure) {
                $this->logger->error('An inbound bank message could not be processed again.', ['message' => $id, 'exception' => $failure]);
            }
        }
    }
}
