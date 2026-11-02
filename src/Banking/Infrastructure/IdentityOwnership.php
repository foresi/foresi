<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Banking\Infrastructure;

use App\Banking\Application\Ownership;
use App\Identity\Application\OwnerCheck;

/**
 * The owner of the active organisation, as the membership says.
 */
final readonly class IdentityOwnership implements Ownership
{
    public function __construct(private OwnerCheck $owner)
    {
    }

    public function isOwner(): bool
    {
        return $this->owner->isOwner();
    }
}
