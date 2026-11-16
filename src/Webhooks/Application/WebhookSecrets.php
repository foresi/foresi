<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Webhooks\Application;

use App\Shared\Application\SealerUnavailable;
use App\Webhooks\Domain\WebhookSecret;

/**
 * Seals the signing secrets of endpoints with keys held outside the database,
 * so a copy of the database alone signs nothing.
 */
interface WebhookSecrets
{
    /**
     * @throws SealerUnavailable when no key is configured
     */
    public function seal(WebhookSecret $secret): string;

    /**
     * @throws SealerUnavailable when no configured key opens the sealed value
     */
    public function open(string $sealed): WebhookSecret;

    /**
     * Whether a key is configured; without one no endpoint can be saved.
     */
    public function isConfigured(): bool;
}
