<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Invoicing\Application;

/**
 * Decides whether a mail server host a tenant typed may be connected to. Tenants
 * choose the host, so without this the server could be made to reach its own
 * network or a cloud metadata address.
 */
interface MailHostPolicy
{
    public function allows(string $host): bool;
}
