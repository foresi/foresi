<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Webhooks\Application;

/**
 * The organisation has no endpoint with this id.
 */
final class UnknownWebhookEndpoint extends \DomainException
{
    public function __construct()
    {
        parent::__construct('No such webhook endpoint.');
    }
}
