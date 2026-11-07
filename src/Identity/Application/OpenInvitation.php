<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Identity\Application;

use App\Identity\Domain\Invitation;
use App\Identity\Domain\InvitationState;

/**
 * An invitation as its link shows it: in which organisation, whether it can still
 * be accepted, and whether the address has an account already.
 */
final readonly class OpenInvitation
{
    public function __construct(
        public Invitation $invitation,
        public string $organisation,
        public InvitationState $state,
        public bool $hasAccount,
    ) {
    }
}
