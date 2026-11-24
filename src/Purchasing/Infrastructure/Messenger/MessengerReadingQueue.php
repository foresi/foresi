<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Purchasing\Infrastructure\Messenger;

use Foresi\Purchasing\Application\Reading\ReadingQueue;
use Foresi\Purchasing\Application\Reading\ReadReceivedDocument;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Sends the reading to the queue, the `async` transport.
 */
final readonly class MessengerReadingQueue implements ReadingQueue
{
    public function __construct(private MessageBusInterface $bus)
    {
    }

    public function queue(ReadReceivedDocument $message): void
    {
        $this->bus->dispatch($message);
    }
}
