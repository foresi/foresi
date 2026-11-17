<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Instance\Application;

use App\Instance\Application\Schedule\TenantDirectory;
use Symfony\Component\Clock\ClockInterface;

/**
 * The zone and the clock of the instance, for work that needs no tenant
 * active. One instance serves one customer (ADR 0035), so its zone is that of
 * its tenant by the directory's rule (a stored zone, else APP_TIMEZONE, else
 * the country of the first active firm, else UTC); with no tenant yet it is
 * APP_TIMEZONE, else UTC. The zone is read each time, as it may change while a
 * worker runs.
 */
final readonly class InstanceClock
{
    public function __construct(
        private TenantDirectory $tenants,
        private ConfiguredZone $configured,
        private ClockInterface $clock,
    ) {
    }

    public function timezone(): \DateTimeZone
    {
        $zones = $this->tenants->zones();
        $first = array_key_first($zones);

        return null === $first
            ? $this->configured->zone() ?? new \DateTimeZone('UTC')
            : $zones[$first];
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
