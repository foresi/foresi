<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Meetings\Application;

use Foresi\Instance\Application\InstanceClock;

/**
 * The moments and days of Meetings, from the instance's clock: a moment kept in
 * UTC as every stored moment, and today in the instance's zone.
 */
final readonly class MeetingsClock
{
    public function __construct(private InstanceClock $clock)
    {
    }

    public function now(): \DateTimeImmutable
    {
        return \DateTimeImmutable::createFromInterface($this->clock->clock()->now())->setTimezone(new \DateTimeZone('UTC'));
    }

    public function zone(): \DateTimeZone
    {
        return $this->clock->timezone();
    }

    public function today(): \DateTimeImmutable
    {
        return $this->clock->today();
    }
}
