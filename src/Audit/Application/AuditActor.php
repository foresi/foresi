<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Audit\Application;

use App\Audit\Domain\ActorKind;

/**
 * Tells the audit log who is acting. Null when nobody is signed in, for
 * example in a console command, and then the system is the actor.
 */
interface AuditActor
{
    public function userId(): ?string;

    public function kind(): ActorKind;
}
