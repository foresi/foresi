<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Purchasing\Infrastructure\Messenger;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use Foresi\Purchasing\Application\Reading\ReadDocument;
use Foresi\Purchasing\Application\Reading\ReadReceivedDocument;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\Event\WorkerMessageFailedEvent;

/**
 * Reads the file of a received document in the worker. Anything unexpected is
 * thrown, so the queue retries it (three times, the transport's default); when
 * the queue gives up, the reading fails with the code `unexpected`.
 *
 * Marking it never stops the worker's own handling of the failure (the reject
 * and the failure transport): an entity manager the failure closed is reset
 * first, and an error is logged by its class and code only, never its text,
 * which may come from a reader or a provider.
 */
#[AsMessageHandler]
final readonly class ReadReceivedDocumentHandler
{
    public function __construct(
        private ReadDocument $read,
        private ManagerRegistry $registry,
        private LoggerInterface $logger,
    ) {
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
        try {
            $manager = $this->registry->getManager();
            if ($manager instanceof EntityManagerInterface && !$manager->isOpen()) {
                $this->registry->resetManager();
            }
            $this->read->giveUp($message);
        } catch (\Throwable $failure) {
            $this->logger->error('The reading of a received document could not be marked failed.', [
                'document' => $message->documentId,
                'error' => $failure::class,
                'code' => $failure->getCode(),
            ]);
        }
    }
}
