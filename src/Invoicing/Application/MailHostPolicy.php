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
    /**
     * Resolves the host once and returns the vetted address to connect to, so the
     * connection cannot be pointed elsewhere by a second lookup.
     *
     * @throws MailRefused permanent when an address of the host is not allowed;
     *                     temporary when the name does not resolve now
     */
    public function vet(string $host): string;

    /**
     * Whether vet() would give an address now, for a check when saving.
     */
    public function allows(string $host): bool;
}
