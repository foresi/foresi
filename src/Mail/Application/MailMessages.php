<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Mail\Application;

use Foresi\Mail\Domain\MailMessage;

/**
 * The stored inbound messages. The dedup key is unique per address.
 */
interface MailMessages
{
    /**
     * Whether a message with this dedup key is stored for the address already.
     */
    public function has(string $addressId, string $dedupKey): bool;

    /**
     * The sha256 of the raw bytes of the address's message with this dedup key,
     * or null when none is stored.
     */
    public function rawHashOf(string $addressId, string $dedupKey): ?string;

    /**
     * Stores a new message by a single statement. Of two messages of one address
     * with one dedup key only the first is stored, even at the same moment; the
     * message object is not managed afterwards.
     *
     * @return bool false when a message with its dedup key is stored already
     */
    public function add(MailMessage $message): bool;

    /**
     * How many messages are stored for the address that arrived at or after the moment.
     */
    public function countSince(string $addressId, \DateTimeImmutable $since): int;

    /**
     * The message as stored now, or null for none.
     */
    public function find(string $id): ?MailMessage;

    /**
     * Sets, or with null clears, the moment from which the message may be purged.
     * Nothing happens for an unknown message.
     */
    public function schedulePurge(string $id, ?\DateTimeImmutable $after): void;

    /**
     * The ids of the messages whose purge date has passed, the earliest first.
     *
     * @return list<string>
     */
    public function duePurge(\DateTimeImmutable $now, int $limit): array;

    /**
     * Removes the row, by one statement.
     *
     * @return bool false when another table still refers to it, so the row stays
     */
    public function delete(string $id): bool;
}
