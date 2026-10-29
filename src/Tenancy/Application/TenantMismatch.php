<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Tenancy\Application;

final class TenantMismatch extends \LogicException
{
    public static function create(): self
    {
        return new self('An entity of another tenant cannot be persisted in the active tenant.');
    }
}
