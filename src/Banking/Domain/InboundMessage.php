<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Banking\Domain;

use Doctrine\ORM\Mapping as ORM;
use Foresi\Banking\Contract\MovementTrust;

/**
 * How far the processing of one bank mail got, as a machine log. The mail
 * itself, its bytes, sender and subject, is Mail's message of the same id
 * (mail_message_id), read through Mail's contract. Not audited, like a
 * scheduled job run; the human actions on it (retry, record anyway, dismiss)
 * are audited on their own.
 *
 * The feed and the mail are named by their ids only: a feed is never deleted,
 * and the row is written before any other entity is loaded.
 */
#[ORM\Entity]
#[ORM\Table(name: 'inbound_message')]
#[ORM\Index(name: 'idx_inbound_message_feed', columns: ['bank_feed_id'])]
#[ORM\Index(name: 'idx_inbound_message_status', columns: ['status'])]
#[ORM\UniqueConstraint(name: 'uniq_inbound_message_mail', columns: ['mail_message_id'])]
class InboundMessage
{
    #[ORM\Column(type: 'string', length: 16, enumType: InboundStatus::class)]
    private InboundStatus $status = InboundStatus::Received;

    #[ORM\Column(type: 'string', length: 16, nullable: true, enumType: MovementTrust::class)]
    private ?MovementTrust $trust = null;

    #[ORM\Column(name: 'trust_basis', type: 'string', length: 32, nullable: true)]
    private ?string $trustBasis = null;

    #[ORM\Column(name: 'parser_id', type: 'string', length: 64, nullable: true)]
    private ?string $parserId = null;

    #[ORM\Column(name: 'parser_version', type: 'integer', nullable: true)]
    private ?int $parserVersion = null;

    #[ORM\Column(type: 'string', length: 32, nullable: true)]
    private ?string $failure = null;

    #[ORM\Column(name: 'failure_detail', type: 'text', nullable: true)]
    private ?string $failureDetail = null;

    #[ORM\Column(type: 'integer')]
    private int $attempts = 0;

    #[ORM\Column(name: 'processed_at', type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $processedAt = null;

    /**
     * @param string $mailMessageId the id of Mail's message; new rows take it as their own id too
     */
    public function __construct(
        #[ORM\Id]
        #[ORM\Column(type: 'guid')]
        private string $id,
        #[ORM\Column(name: 'bank_feed_id', type: 'guid')]
        private string $feedId,
        #[ORM\Column(name: 'mail_message_id', type: 'guid')]
        private string $mailMessageId,
    ) {
    }

    public function id(): string
    {
        return $this->id;
    }

    public function feedId(): string
    {
        return $this->feedId;
    }

    public function mailMessageId(): string
    {
        return $this->mailMessageId;
    }

    public function status(): InboundStatus
    {
        return $this->status;
    }

    public function trust(): ?MovementTrust
    {
        return $this->trust;
    }

    public function trustBasis(): ?string
    {
        return $this->trustBasis;
    }

    public function parserId(): ?string
    {
        return $this->parserId;
    }

    public function parserVersion(): ?int
    {
        return $this->parserVersion;
    }

    public function failure(): ?string
    {
        return $this->failure;
    }

    public function failureDetail(): ?string
    {
        return $this->failureDetail;
    }

    public function attempts(): int
    {
        return $this->attempts;
    }

    public function processedAt(): ?\DateTimeImmutable
    {
        return $this->processedAt;
    }
}
