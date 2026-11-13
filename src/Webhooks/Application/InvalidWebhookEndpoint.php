<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Webhooks\Application;

/**
 * A value of an endpoint that cannot be used, named as the API and MCP take it
 * (event_types, version, description, keep_previous_hours). A URL that may not
 * receive webhooks is an InvalidWebhookUrl instead.
 */
final class InvalidWebhookEndpoint extends \InvalidArgumentException
{
    public function __construct(public readonly string $parameter, string $message)
    {
        parent::__construct($message);
    }
}
