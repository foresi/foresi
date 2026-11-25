<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Mail\Contract\Event;

use Foresi\Shared\Contract\Event\DomainEvent;
use Foresi\Shared\Contract\Event\DomainEventPayload;
use Foresi\Shared\Contract\Event\EventSubject;

/**
 * A mail arrived for an inbound address and was handed to its purpose: the id of
 * the stored message and the key of the purpose that took it. Thin on purpose
 * (ADR 0030): no address, sender, subject or text. The consumer that owns the
 * purpose reads the message through Foresi\Mail\Contract\Messages.
 */
#[DomainEvent('mail.message.received', version: 1)]
final readonly class MailMessageReceived implements DomainEventPayload
{
    public function __construct(
        public string $messageId,
        public string $purpose,
    ) {
    }

    public function subject(): EventSubject
    {
        return new EventSubject('mail_message', $this->messageId);
    }
}
