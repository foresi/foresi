<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Banking\Application;

/**
 * Processes one stored inbound message.
 */
final readonly class ProcessInboundMessage
{
    public function __construct(public string $messageId)
    {
    }
}
