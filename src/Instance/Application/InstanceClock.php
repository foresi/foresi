<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Instance\Application;

use Symfony\Component\Clock\ClockInterface;

/**
 * The zone and the clock of the instance. One instance serves one customer
 * (ADR 0035), so it has one zone: APP_TIMEZONE when it is set, else the
 * fallback's (the country of the firm, ADR 0039), else UTC. The zone is
 * read each time, as a firm may be set up while a worker runs.
 */
final readonly class InstanceClock
{
    public function __construct(
        private ConfiguredZone $configured,
        private ZoneFallback $fallback,
        private ClockInterface $clock,
    ) {
    }

    public function timezone(): \DateTimeZone
    {
        return $this->configured->zone() ?? $this->fallback->zone() ?? new \DateTimeZone('UTC');
    }

    /**
     * The clock whose now() is in the instance's zone.
     */
    public function clock(): ClockInterface
    {
        return $this->clock->withTimeZone($this->timezone());
    }

    /**
     * Midnight of today in the instance's zone.
     */
    public function today(): \DateTimeImmutable
    {
        return $this->clock()->now()->setTime(0, 0);
    }
}
