<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Purchasing\Infrastructure\Messenger;

use Foresi\Purchasing\Application\Reading\ReadDocument;
use Foresi\Purchasing\Application\Reading\ReadReceivedDocument;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\Event\WorkerMessageFailedEvent;

/**
 * Reads the file of a received document in the worker. Anything unexpected is
 * thrown, so the queue retries it (three times, the transport's default); when
 * the queue gives up, the reading fails with the code `unexpected`.
 */
#[AsMessageHandler]
final readonly class ReadReceivedDocumentHandler
{
    public function __construct(private ReadDocument $read)
    {
    }

    public function __invoke(ReadReceivedDocument $message): void
    {
        $this->read->read($message);
    }

    #[AsEventListener]
    public function onFailure(WorkerMessageFailedEvent $event): void
    {
        $message = $event->getEnvelope()->getMessage();
        if ($event->willRetry() || !$message instanceof ReadReceivedDocument) {
            return;
        }
        $this->read->giveUp($message);
    }
}
