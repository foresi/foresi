<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Tenancy\Infrastructure\Health;

/**
 * How one check, or the installation as a whole, stands: degraded still serves
 * and needs a look, down does not do its work.
 */
enum HealthStatus: string
{
    case Ok = 'ok';
    case Degraded = 'degraded';
    case Down = 'down';

    public function worse(self $other): self
    {
        return $other->rank() > $this->rank() ? $other : $this;
    }

    private function rank(): int
    {
        return match ($this) {
            self::Ok => 0,
            self::Degraded => 1,
            self::Down => 2,
        };
    }
}
