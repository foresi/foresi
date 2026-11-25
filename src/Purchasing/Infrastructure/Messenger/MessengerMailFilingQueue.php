<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Purchasing\Infrastructure\Messenger;

use Foresi\Purchasing\Application\Mail\FileMailAttachments;
use Foresi\Purchasing\Application\Mail\MailFilingQueue;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Sends the filing of a mail to the queue, the `async` transport.
 */
final readonly class MessengerMailFilingQueue implements MailFilingQueue
{
    public function __construct(private MessageBusInterface $bus)
    {
    }

    public function queue(FileMailAttachments $message): void
    {
        $this->bus->dispatch($message);
    }
}
