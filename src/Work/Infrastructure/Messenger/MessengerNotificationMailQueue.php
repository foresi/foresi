<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Work\Infrastructure\Messenger;

use App\Work\Application\NotificationMailer;
use App\Work\Application\NotificationMailQueue;
use App\Work\Application\SendNotificationMail;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\DelayStamp;

/**
 * Puts the e-mail of a direct event on the default queue with a delay
 * (app.notification_mail_delay, two minutes), so a person who reads it in the
 * inbox first gets no e-mail. Nothing is queued while the installation has no
 * system mailer; a queue that cannot take it costs the e-mail only.
 */
final readonly class MessengerNotificationMailQueue implements NotificationMailQueue
{
    public function __construct(
        private MessageBusInterface $bus,
        private NotificationMailer $mailer,
        private LoggerInterface $logger,
        #[Autowire(param: 'app.notification_mail_delay')]
        private int $delaySeconds,
    ) {
    }

    public function queue(SendNotificationMail $mail): void
    {
        if (!$this->mailer->isConfigured()) {
            return;
        }
        try {
            $this->bus->dispatch($mail, [new DelayStamp($this->delaySeconds * 1000)]);
        } catch (\Throwable $failure) {
            $this->logger->warning('The e-mail of the notification {notification} was not handed to the worker: {reason}', ['notification' => $mail->notificationId, 'reason' => $failure::class]);
        }
    }
}
