<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Banking\Application;

use App\Tenancy\Application\TenantScopedMessage;

/**
 * Processes one stored inbound message in the tenant the message carries.
 */
final readonly class ProcessInboundMessage implements TenantScopedMessage
{
    public function __construct(public string $messageId)
    {
    }
}
