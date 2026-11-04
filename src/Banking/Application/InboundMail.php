<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Banking\Application;

/**
 * One received message, read once and then free of any MIME library. Header
 * values are unfolded but otherwise as written, so a trust check sees exactly
 * what the receiving servers added. The bodies are decoded to UTF-8; an HTML-only
 * mail has no text, and an attachment is never part of either body.
 */
final readonly class InboundMail
{
    private ?string $from;

    /**
     * @param list<array{string, string}> $headers         name and value of every header field, in message order
     * @param string|null                 $from            the sender's address, or null when the message names none
     * @param string|null                 $messageId       the Message-ID without its angle brackets
     * @param list<string>                $attachmentNames
     * @param string                      $sha256          hex digest of the raw message
     * @param int                         $size            length of the raw message in bytes
     */
    public function __construct(
        private array $headers,
        ?string $from,
        private string $subject,
        private ?string $messageId,
        private ?\DateTimeImmutable $date,
        private ?string $text,
        private ?string $html,
        private array $attachmentNames,
        private string $sha256,
        private int $size,
    ) {
        $this->from = null === $from || '' === trim($from) ? null : strtolower(trim($from));
    }

    /**
     * Every value of a header, topmost first; the name is matched case-insensitively.
     *
     * @return list<string>
     */
    public function headers(string $name): array
    {
        $values = [];
        foreach ($this->headers as [$headerName, $value]) {
            if (0 === strcasecmp($headerName, $name)) {
                $values[] = $value;
            }
        }

        return $values;
    }

    /**
     * The one sender address, lowercased.
     */
    public function from(): ?string
    {
        return $this->from;
    }

    public function fromDomain(): ?string
    {
        if (null === $this->from) {
            return null;
        }
        $at = strrpos($this->from, '@');
        if (false === $at || $at === \strlen($this->from) - 1) {
            return null;
        }

        return substr($this->from, $at + 1);
    }

    public function subject(): string
    {
        return $this->subject;
    }

    public function messageId(): ?string
    {
        return $this->messageId;
    }

    public function date(): ?\DateTimeImmutable
    {
        return $this->date;
    }

    public function text(): ?string
    {
        return $this->text;
    }

    public function html(): ?string
    {
        return $this->html;
    }

    /**
     * @return list<string>
     */
    public function attachmentNames(): array
    {
        return $this->attachmentNames;
    }

    public function sha256(): string
    {
        return $this->sha256;
    }

    public function size(): int
    {
        return $this->size;
    }
}
