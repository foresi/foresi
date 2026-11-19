<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Attachment\Application;

use Foresi\Instance\Application\Schedule\DailyPeriod;
use Foresi\Instance\Application\Schedule\ScheduledJob;
use Psr\Clock\ClockInterface;

/**
 * Removes the attachments deleted more than 30 days ago, once a day from 03:00
 * in the instance's zone, as the trash of tasks.
 */
final readonly class PurgeAttachmentTrashDaily implements ScheduledJob
{
    public const string NAME = 'attachment-trash-purge';
    public const int START_HOUR = 3;

    public function __construct(private PurgeDeletedAttachments $purge, private ClockInterface $clock)
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
        $this->purge->run(\DateTimeImmutable::createFromInterface($this->clock->now()));
    }
}
