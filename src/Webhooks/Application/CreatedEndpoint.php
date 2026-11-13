<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Webhooks\Application;

use App\Webhooks\Domain\WebhookEndpoint;
use App\Webhooks\Domain\WebhookSecret;

/**
 * A new endpoint and its signing secret, which the receiver needs to verify
 * what it gets; the answer to the creation is one of the few places it is shown.
 */
final readonly class CreatedEndpoint
{
    public function __construct(public WebhookEndpoint $endpoint, public WebhookSecret $secret)
    {
    }
}
