<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Inbox\Domain;

/**
 * What groups the mails of one conversation: the SHA-256, in lower-case hex, of
 * the Message-ID the conversation started with. A reply names it as the first id
 * of its References, or else as its In-Reply-To; the first mail of a conversation
 * names only its own Message-ID; a mail without any of them is a conversation of
 * its own, keyed by the id of its item.
 */
final readonly class ThreadKey
{
    public const int LENGTH = 64;

    private function __construct(public string $value)
    {
    }

    /**
     * @param list<string> $references the Message-IDs of the References header, oldest first, without angle brackets
     */
    public static function of(array $references, ?string $inReplyTo, ?string $messageId, string $itemId): self
    {
        foreach ([$references[0] ?? null, $inReplyTo, $messageId] as $id) {
            $id = trim((string) $id, " \t<>");
            if ('' !== $id) {
                return new self(hash('sha256', $id));
            }
        }

        return new self(hash('sha256', $itemId));
    }
}
