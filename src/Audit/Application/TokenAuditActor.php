<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Audit\Application;

/**
 * An audit actor that may act through a personal API token: the entry then
 * names the token next to the person. Null for a sign-in on the screens.
 */
interface TokenAuditActor extends AuditActor
{
    public function apiTokenId(): ?string;
}
