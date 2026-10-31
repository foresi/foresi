<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Audit\Application;

/**
 * Tells the audit log who is acting. Null when nobody is signed in, for
 * example in a console command.
 */
interface AuditActor
{
    public function userId(): ?string;
}
