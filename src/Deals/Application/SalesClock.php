<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Deals\Application;

use Foresi\Instance\Application\InstanceClock;

/**
 * The moment of a write, from the instance's clock, kept in UTC as every stored moment.
 */
final readonly class SalesClock
{
    public function __construct(private InstanceClock $clock)
    {
    }

    public function now(): \DateTimeImmutable
    {
        return \DateTimeImmutable::createFromInterface($this->clock->clock()->now())->setTimezone(new \DateTimeZone('UTC'));
    }
}
