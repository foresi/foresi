<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Invoicing\Application;

/**
 * Seals the passwords of mail servers with keys held outside the database, so a
 * copy of the database alone opens none of them.
 */
interface MailSecrets
{
    /**
     * @throws MailSecretsUnavailable when no key is configured
     */
    public function seal(string $secret): string;

    /**
     * @throws MailSecretsUnavailable when no configured key opens the sealed value
     */
    public function open(string $sealed): string;

    /**
     * Whether a key is configured; without one no mail server can be saved.
     */
    public function isConfigured(): bool;
}
