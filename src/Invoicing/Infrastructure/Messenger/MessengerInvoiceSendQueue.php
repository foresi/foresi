<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Invoicing\Infrastructure\Messenger;

use App\Invoicing\Application\DeliverInvoiceSend;
use App\Invoicing\Application\InvoiceSendQueue;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Puts a send on the mail queue. The send is committed by then: if the queue
 * cannot take it, or a synchronous transport delivers it at once and fails, the
 * send says so itself, and the request that queued it goes on.
 */
final readonly class MessengerInvoiceSendQueue implements InvoiceSendQueue
{
    public function __construct(private MessageBusInterface $bus, private LoggerInterface $logger)
    {
    }

    public function deliver(string $sendId): void
    {
        try {
            $this->bus->dispatch(new DeliverInvoiceSend($sendId));
        } catch (\Throwable $failure) {
            $this->logger->warning('The send {send} was stored but not delivered at once: {reason}', ['send' => $sendId, 'reason' => $failure::class]);
        }
    }
}
