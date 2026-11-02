<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Banking\Infrastructure\Messenger;

use App\Banking\Application\InboundProcessing;
use App\Banking\Application\ProcessInboundMessage;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Sends the message to the queue; the tenant middleware stamps the active tenant.
 */
final readonly class MessengerInboundProcessing implements InboundProcessing
{
    public function __construct(private MessageBusInterface $bus)
    {
    }

    public function queue(string $messageId): void
    {
        $this->bus->dispatch(new ProcessInboundMessage($messageId));
    }
}
