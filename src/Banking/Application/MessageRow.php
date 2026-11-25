<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Banking\Application;

use Foresi\Banking\Contract\MovementTrust;
use Foresi\Banking\Domain\InboundStatus;

/**
 * One bank mail as the Messages tab lists it. The parser is named only when one
 * read it: a mail no parser or several parsers took holds the signature of the
 * whole set instead, which names no parser.
 */
final readonly class MessageRow
{
    /**
     * @param bool $stuck still waiting for processing past the time the queue takes
     */
    public function __construct(
        public string $id,
        public \DateTimeImmutable $receivedAt,
        public ?string $from,
        public string $subject,
        public InboundStatus $status,
        public ?string $failure,
        public ?string $failureDetail,
        public ?MovementTrust $trust,
        public ?string $trustBasis,
        public ?string $parserId,
        public ?int $parserVersion,
        public int $attempts,
        public string $accountLabel,
        public string $accountIban,
        public bool $stuck,
    ) {
    }

    /**
     * A failed one, one no parser knew, and one left waiting can be processed again.
     */
    public function canRetry(): bool
    {
        return InboundStatus::Failed === $this->status || InboundStatus::NotBankMail === $this->status || $this->stuck;
    }

    /**
     * Only a rejection of the sender can be recorded anyway; a message about an account
     * that is not the firm's never can.
     */
    public function canAccept(): bool
    {
        return InboundStatus::Rejected === $this->status && ProcessMessage::UNTRUSTED === $this->failure;
    }

    /**
     * Whatever waits for someone, and a mail no parser knew, can be set aside.
     */
    public function canDismiss(): bool
    {
        return \in_array($this->status, DismissInboundMessage::FROM, true) && (InboundStatus::Received !== $this->status || $this->stuck);
    }
}
