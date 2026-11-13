<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Webhooks\Application;

/**
 * Nothing is sent to a disabled endpoint, by hand or otherwise: enable it first.
 */
final class WebhookEndpointDisabled extends \DomainException
{
    public function __construct()
    {
        parent::__construct('The webhook endpoint is disabled. Enable it first.');
    }
}
