<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Tenancy\Application\Schedule;

use App\Tenancy\Domain\TenantId;

/**
 * Port: every tenant of the installation, for work that visits each in turn.
 */
interface TenantDirectory
{
    /**
     * @return list<TenantId>
     */
    public function ids(): array;
}
