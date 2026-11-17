<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Tests\Events\Support;

use App\Instance\Application\Schedule\TenantDirectory;
use App\Instance\Application\SoleTenant;
use App\Instance\Domain\TenantId;

/**
 * The one tenant of an instance, without a tenant table behind it, for the tests
 * of the background work that stamps its messages with it.
 */
final class SoleTenants
{
    public static function of(TenantId $tenant): SoleTenant
    {
        return new SoleTenant(new readonly class($tenant) implements TenantDirectory {
            public function __construct(private TenantId $tenant)
            {
            }

            public function ids(): array
            {
                return [$this->tenant];
            }

            public function zones(): array
            {
                return [$this->tenant->toString() => new \DateTimeZone('UTC')];
            }

            public function zone(TenantId $tenant): \DateTimeZone
            {
                return new \DateTimeZone('UTC');
            }
        });
    }
}
