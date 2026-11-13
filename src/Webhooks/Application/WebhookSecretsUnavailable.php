<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Webhooks\Application;

/**
 * No key to seal or open signing secrets is configured (WEBHOOK_SECRET_KEYS),
 * so no endpoint can be saved, rotated, revealed or sent to until the operator
 * sets one.
 */
final class WebhookSecretsUnavailable extends \RuntimeException
{
    public function __construct(?\Throwable $previous = null)
    {
        parent::__construct('Webhooks cannot be signed on this installation yet: the operator has to configure WEBHOOK_SECRET_KEYS.', 0, $previous);
    }
}
