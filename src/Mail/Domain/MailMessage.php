<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Mail\Domain;

use Doctrine\ORM\Mapping as ORM;

/**
 * One mail that arrived for an inbound address, as a machine log: what came,
 * where its bytes are and what its receiving servers said of its sender. Not
 * audited, like a scheduled job run.
 *
 * The raw message is a file named by raw_key, and its sha256 is kept to tell a
 * changed file. The dedup key is the sha256 of the Message-ID, or of the raw
 * bytes when there is none or another message with other bytes holds that
 * Message-ID, and is unique per address: the same mail fetched and posted is one
 * row. Rows are inserted by a single statement that skips a duplicate, so the
 * two never race. The parts of the message are read from the file when asked
 * for, never copied.
 */
#[ORM\Entity]
#[ORM\Table(name: 'mail_message')]
#[ORM\UniqueConstraint(name: 'uniq_mail_message_dedup', columns: ['address_id', 'dedup_key'])]
#[ORM\Index(name: 'idx_mail_message_message_id', columns: ['message_id_header'])]
class MailMessage
{
    public const int MAX_FROM = 320;
    public const int MAX_SUBJECT = 255;
    public const int MAX_RAW_KEY = 128;
    public const int MAX_HEADER = 255;

    #[ORM\Column(type: 'string', length: self::MAX_SUBJECT)]
    private string $subject;

    /** The Date the sender wrote, in UTC, as the zone it named is not kept. */
    #[ORM\Column(name: 'sent_at', type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $sentAt;

    #[ORM\Column(name: 'from_address', type: 'string', length: self::MAX_FROM, nullable: true)]
    private ?string $fromAddress;

    #[ORM\Column(name: 'message_id_header', type: 'string', length: self::MAX_HEADER, nullable: true)]
    private ?string $messageIdHeader;

    #[ORM\Column(name: 'in_reply_to', type: 'string', length: self::MAX_HEADER, nullable: true)]
    private ?string $inReplyTo;

    /** Space-separated Message-IDs, oldest first; named so, as REFERENCES is a reserved word in SQL. */
    #[ORM\Column(name: 'references_header', type: 'text', nullable: true)]
    private ?string $references;

    /**
     * @param string       $dedupKey   sha256 in lowercase hex
     * @param string       $rawSha256  sha256 of the raw message in lowercase hex
     * @param list<string> $references
     * @param string       $verdict    verified, failed or unknown
     *
     * @throws \InvalidArgumentException for a malformed key, hash, size or verdict
     */
    public function __construct(
        #[ORM\Id]
        #[ORM\Column(type: 'guid')]
        private string $id,
        #[ORM\ManyToOne(targetEntity: InboundAddress::class)]
        #[ORM\JoinColumn(name: 'address_id', nullable: false, onDelete: 'RESTRICT')]
        private InboundAddress $address,
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
        ?string $messageIdHeader,
        ?string $inReplyTo,
        array $references,
        ?string $fromAddress,
        string $subject,
        ?\DateTimeImmutable $sentAt,
        #[ORM\Column(name: 'received_at', type: 'datetime_immutable')]
        private \DateTimeImmutable $receivedAt,
        #[ORM\Column(type: 'string', length: 16)]
        private string $verdict,
        #[ORM\Column(name: 'verdict_basis', type: 'string', length: 32, nullable: true)]
        private ?string $verdictBasis,
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
        if (!\in_array($verdict, ['verified', 'failed', 'unknown'], true)) {
            throw new \InvalidArgumentException('A verdict is verified, failed or unknown.');
        }
        $from = null === $fromAddress ? '' : trim($fromAddress);
        $this->fromAddress = '' === $from ? null : mb_substr($from, 0, self::MAX_FROM);
        $this->subject = mb_substr($subject, 0, self::MAX_SUBJECT);
        $this->sentAt = $sentAt?->setTimezone(new \DateTimeZone('UTC'));
        $this->messageIdHeader = $this->header($messageIdHeader);
        $this->inReplyTo = $this->header($inReplyTo);
        $references = array_filter(array_map(trim(...), $references), static fn (string $id): bool => '' !== $id);
        $this->references = [] === $references ? null : implode(' ', $references);
    }

    public function id(): string
    {
        return $this->id;
    }

    public function address(): InboundAddress
    {
        return $this->address;
    }

    public function addressId(): string
    {
        return $this->address->id();
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

    public function messageIdHeader(): ?string
    {
        return $this->messageIdHeader;
    }

    public function inReplyTo(): ?string
    {
        return $this->inReplyTo;
    }

    /**
     * @return list<string>
     */
    public function references(): array
    {
        return null === $this->references ? [] : explode(' ', $this->references);
    }

    public function fromAddress(): ?string
    {
        return $this->fromAddress;
    }

    public function subject(): string
    {
        return $this->subject;
    }

    public function sentAt(): ?\DateTimeImmutable
    {
        return $this->sentAt;
    }

    public function receivedAt(): \DateTimeImmutable
    {
        return $this->receivedAt;
    }

    public function verdict(): string
    {
        return $this->verdict;
    }

    public function verdictBasis(): ?string
    {
        return $this->verdictBasis;
    }

    private function header(?string $value): ?string
    {
        $value = null === $value ? '' : trim($value);

        return '' === $value ? null : mb_substr($value, 0, self::MAX_HEADER);
    }
}
