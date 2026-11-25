<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\Purchasing\Support;

use Foresi\Mail\Contract\InboundMail;
use Foresi\Mail\Contract\MailAttachmentContent;
use Foresi\Mail\Contract\MailMessageView;
use Foresi\Mail\Contract\Messages;
use Foresi\Mail\Contract\SenderCheck;

/**
 * Mail's stored messages with one message in them, known only by what its view says.
 */
final readonly class StoredMessages implements Messages
{
    public function __construct(
        private string $id,
        private ?string $from,
        private string $subject,
        private ?\DateTimeImmutable $sentAt,
        private string $verdict = 'verified',
    ) {
    }

    public function find(string $messageId): ?MailMessageView
    {
        return $messageId === $this->id
            ? new MailMessageView($this->id, 'address-1', 'received_documents', null, 'webhook', null, null, [], $this->from, $this->subject, $this->sentAt, $this->sentAt ?? new \DateTimeImmutable('2026-11-20 09:00:00'), $this->verdict, str_repeat('a', 64), 100)
            : null;
    }

    public function raw(string $messageId): string
    {
        throw new \LogicException('Not stored.');
    }

    public function mail(string $messageId): InboundMail
    {
        throw new \LogicException('Not stored.');
    }

    public function text(string $messageId): ?string
    {
        return null;
    }

    public function attachments(string $messageId): array
    {
        return [];
    }

    public function attachment(string $messageId, int $index): MailAttachmentContent
    {
        throw new \LogicException('Not stored.');
    }

    public function authenticationResults(string $messageId): array
    {
        return [];
    }

    public function verdict(string $messageId, array $senderDomains): SenderCheck
    {
        throw new \LogicException('Not stored.');
    }
}
