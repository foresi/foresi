<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Tenancy\Application\Schedule;

use App\Tenancy\Application\TenantScopedMessage;

/**
 * Runs one job for one period in the tenant the message carries.
 */
final readonly class RunScheduledJob implements TenantScopedMessage
{
    public function __construct(
        public string $job,
        public string $period,
    ) {
    }
}
