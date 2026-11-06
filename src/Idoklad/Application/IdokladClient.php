<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Idoklad\Application;

/**
 * Port: opens a read-only session on one iDoklad account.
 */
interface IdokladClient
{
    public function session(string $clientId, #[\SensitiveParameter] string $clientSecret): IdokladSession;
}
