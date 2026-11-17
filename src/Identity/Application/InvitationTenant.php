<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Identity\Application;

use App\Instance\Domain\TenantId;

/**
 * The organisation an invitation link leads into.
 */
final readonly class InvitationTenant
{
    public function __construct(public TenantId $id, public string $name)
    {
    }
}
