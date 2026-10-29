<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Tenancy\Application;

final class NoActiveTenant extends \LogicException
{
    public static function create(): self
    {
        return new self('No active tenant: set the TenantContext before tenant-owned data is accessed.');
    }
}
