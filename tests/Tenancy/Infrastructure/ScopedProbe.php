<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Tests\Tenancy\Infrastructure;

use App\Tenancy\Application\TenantScopedMessage;

final readonly class ScopedProbe implements TenantScopedMessage
{
}
