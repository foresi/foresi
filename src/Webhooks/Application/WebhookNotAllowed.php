<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Webhooks\Application;

/**
 * The caller may not see or change this endpoint, or subscribe to these events:
 * only people who may read every module an endpoint subscribes to manage it.
 */
final class WebhookNotAllowed extends \DomainException
{
    public function __construct(string $message = 'You may not manage webhooks of events you may not read.')
    {
        parent::__construct($message);
    }
}
