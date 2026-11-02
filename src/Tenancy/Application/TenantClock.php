<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Tenancy\Application;

use App\Tenancy\Application\Schedule\TenantDirectory;
use Psr\Clock\ClockInterface;

/**
 * The zone and the date of today in the active tenant. The zone is read each
 * time, as a long-running worker serves many tenants and a zone may change.
 */
final readonly class TenantClock
{
    public function __construct(
        private TenantContext $context,
        private TenantDirectory $tenants,
        private ClockInterface $clock,
    ) {
    }

    /**
     * @throws NoActiveTenant
     */
    public function timezone(): \DateTimeZone
    {
        return $this->tenants->zone($this->context->current());
    }

    /**
     * Midnight of today in the tenant's zone.
     *
     * @throws NoActiveTenant
     */
    public function today(): \DateTimeImmutable
    {
        return \DateTimeImmutable::createFromInterface($this->clock->now())->setTimezone($this->timezone())->setTime(0, 0);
    }
}
