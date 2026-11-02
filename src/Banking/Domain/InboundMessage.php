<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Banking\Domain;

use App\Tenancy\Domain\TenantId;
use App\Tenancy\Domain\TenantOwned;
use Doctrine\ORM\Mapping as ORM;

/**
 * One mail that arrived for an inbound address, as a machine log: what came,
 * where its bytes are and how far processing got. Not audited, like a
 * scheduled job run; the human actions on it (retry, record anyway) are
 * audited through the movement they create.
 *
 * The raw message is a file named by raw_key, and its sha256 is kept to tell a
 * changed file. The dedup key is the sha256 of the Message-ID, or of the raw
 * bytes when there is none, and is unique in the tenant: the same mail fetched
 * and posted, or forwarded twice, is one row. Rows are inserted by a single
 * statement that skips a duplicate, so the two never race.
 *
 * The feed is named by its id only: a feed is never deleted, and the row is
 * written before any entity of the tenant is loaded.
 */
#[ORM\Entity]
#[ORM\Table(name: 'inbound_message')]
#[ORM\Index(name: 'idx_inbound_message_tenant', columns: ['tenant_id'])]
#[ORM\Index(name: 'idx_inbound_message_feed', columns: ['bank_feed_id'])]
#[ORM\Index(name: 'idx_inbound_message_status', columns: ['status'])]
#[ORM\UniqueConstraint(name: 'uniq_inbound_message_dedup', columns: ['tenant_id', 'dedup_key'])]
class InboundMessage implements TenantOwned
{
    public const int MAX_FROM = 320;
    public const int MAX_SUBJECT = 255;
    public const int MAX_RAW_KEY = 128;

    #[ORM\Column(name: 'tenant_id', type: 'tenant_id', nullable: false)]
    private ?TenantId $tenantId = null;

    #[ORM\Column(type: 'string', length: 16, enumType: InboundStatus::class)]
    private InboundStatus $status = InboundStatus::Received;

    #[ORM\Column(type: 'string', length: 16, nullable: true, enumType: MessageTrust::class)]
    private ?MessageTrust $trust = null;

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

    #[ORM\Column(type: 'string', length: self::MAX_SUBJECT)]
    private string $subject;

    #[ORM\Column(name: 'from_address', type: 'string', length: self::MAX_FROM, nullable: true)]
    private ?string $fromAddress;

    /**
     * @param string $dedupKey  sha256 in lowercase hex
     * @param string $rawSha256 sha256 of the raw message in lowercase hex
     *
     * @throws \InvalidArgumentException for a malformed key, hash or size
     */
    public function __construct(
        #[ORM\Id]
        #[ORM\Column(type: 'guid')]
        private string $id,
        #[ORM\Column(name: 'bank_feed_id', type: 'guid')]
        private string $feedId,
        #[ORM\Column(type: 'string', length: 16, enumType: InboundChannel::class)]
        private InboundChannel $channel,
        #[ORM\Column(name: 'dedup_key', type: 'string', length: 64, options: ['fixed' => true])]
        private string $dedupKey,
        #[ORM\Column(name: 'raw_key', type: 'string', length: self::MAX_RAW_KEY)]
        private string $rawKey,
        #[ORM\Column(name: 'raw_sha256', type: 'string', length: 64, options: ['fixed' => true])]
        private string $rawSha256,
        #[ORM\Column(type: 'integer')]
        private int $size,
        ?string $fromAddress,
        string $subject,
        #[ORM\Column(name: 'received_at', type: 'datetime_immutable')]
        private \DateTimeImmutable $receivedAt,
    ) {
        foreach ([$dedupKey, $rawSha256] as $hash) {
            if (1 !== preg_match('/^[0-9a-f]{64}$/D', $hash)) {
                throw new \InvalidArgumentException('A dedup key and a raw hash are sha256 in lowercase hex.');
            }
        }
        if ('' === $rawKey || \strlen($rawKey) > self::MAX_RAW_KEY) {
            throw new \InvalidArgumentException('The key of the raw file is missing or too long.');
        }
        if ($size < 0) {
            throw new \InvalidArgumentException('A size is never negative.');
        }
        $from = null === $fromAddress ? '' : trim($fromAddress);
        $this->fromAddress = '' === $from ? null : mb_substr($from, 0, self::MAX_FROM);
        $this->subject = mb_substr($subject, 0, self::MAX_SUBJECT);
    }

    public function id(): string
    {
        return $this->id;
    }

    public function tenantId(): TenantId
    {
        return $this->tenantId ?? throw new \LogicException('The tenant is stamped on persist.');
    }

    public function feedId(): string
    {
        return $this->feedId;
    }

    public function channel(): InboundChannel
    {
        return $this->channel;
    }

    public function dedupKey(): string
    {
        return $this->dedupKey;
    }

    public function rawKey(): string
    {
        return $this->rawKey;
    }

    public function rawSha256(): string
    {
        return $this->rawSha256;
    }

    public function size(): int
    {
        return $this->size;
    }

    public function fromAddress(): ?string
    {
        return $this->fromAddress;
    }

    public function subject(): string
    {
        return $this->subject;
    }

    public function receivedAt(): \DateTimeImmutable
    {
        return $this->receivedAt;
    }

    public function status(): InboundStatus
    {
        return $this->status;
    }

    public function trust(): ?MessageTrust
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
