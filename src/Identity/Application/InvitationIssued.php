<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Identity\Application;

use App\Identity\Domain\Invitation;

/**
 * An invitation just created or sent again, with the link that works now. When
 * it was not mailed, the owner passes the link on themselves.
 */
final readonly class InvitationIssued
{
    public function __construct(public Invitation $invitation, public string $link, public bool $mailed)
    {
    }
}
