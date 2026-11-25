<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Mail\Contract;

/**
 * One stored inbound message as other modules read it: plain values. The
 * subject and the sender are the sender's own text.
 */
final readonly class MailMessageView
{
    /**
     * @param string       $channel    imap or webhook, the path the message came by
     * @param list<string> $references the Message-IDs of the References header, oldest first
     * @param string       $verdict    verified, failed or unknown, judged against the From domain on arrival
     * @param string       $sha256     of the raw bytes, lowercase hex
     */
    public function __construct(
        public string $id,
        public string $addressId,
        public string $purpose,
        public ?string $ownerUserId,
        public string $channel,
        public ?string $messageIdHeader,
        public ?string $inReplyTo,
        public array $references,
        public ?string $from,
        public string $subject,
        public ?\DateTimeImmutable $sentAt,
        public \DateTimeImmutable $receivedAt,
        public string $verdict,
        public string $sha256,
        public int $size,
    ) {
    }
}
