<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Tenancy\Application;

final class TenantAlreadySet extends \LogicException
{
    public static function create(): self
    {
        return new self('A different tenant is already active: use TenantContext::switchTo() to change tenant.');
    }
}
