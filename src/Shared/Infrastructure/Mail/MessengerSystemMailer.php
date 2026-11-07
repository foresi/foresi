<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Shared\Infrastructure\Mail;

use App\Shared\Application\SystemMail;
use App\Shared\Application\SystemMailer;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Mime\Address;

/**
 * Puts system mail on the mail queue (config/packages/messenger.yaml), with its
 * retries, so a slow or greylisting server never holds up the request. Refuses
 * at once, before queueing, when the installation has no mailer or the
 * recipient is no address.
 */
final readonly class MessengerSystemMailer implements SystemMailer
{
    public function __construct(private SystemMailSettings $settings, private MessageBusInterface $bus)
    {
    }

    public function send(SystemMail $mail): void
    {
        $this->settings->from();
        new Address($mail->to);
        $this->bus->dispatch($mail);
    }

    public function isConfigured(): bool
    {
        return $this->settings->isConfigured();
    }
}
