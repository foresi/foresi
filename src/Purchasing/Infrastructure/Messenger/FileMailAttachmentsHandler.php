<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Purchasing\Infrastructure\Messenger;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use Foresi\Purchasing\Application\Mail\FileMailAttachments;
use Foresi\Purchasing\Application\Mail\MailAttachmentFiling;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\Event\WorkerMessageFailedEvent;

/**
 * Files the attachments of a stored mail in the worker. A delivery for a mail
 * that is not there, or was filed already, does nothing. Anything unexpected is
 * thrown, so the queue retries it (three times, the transport's default); when
 * the queue gives up, the mail is marked failed.
 *
 * Marking it never stops the worker's own handling of the failure: an entity
 * manager the failure closed is reset first, and an error is logged by its
 * class and code only, never its text.
 */
#[AsMessageHandler]
final readonly class FileMailAttachmentsHandler
{
    public function __construct(
        private MailAttachmentFiling $filing,
        private ManagerRegistry $registry,
        private LoggerInterface $logger,
    ) {
    }

    public function __invoke(FileMailAttachments $message): void
    {
        $this->filing->file($message->messageId);
    }

    #[AsEventListener]
    public function onFailure(WorkerMessageFailedEvent $event): void
    {
        $message = $event->getEnvelope()->getMessage();
        if ($event->willRetry() || !$message instanceof FileMailAttachments) {
            return;
        }
        try {
            $manager = $this->registry->getManager();
            if ($manager instanceof EntityManagerInterface && !$manager->isOpen()) {
                $this->registry->resetManager();
            }
            $this->filing->giveUp($message->messageId);
        } catch (\Throwable $failure) {
            $this->logger->error('The filing of a mail could not be marked failed.', [
                'message' => $message->messageId,
                'error' => $failure::class,
                'code' => $failure->getCode(),
            ]);
        }
    }
}
