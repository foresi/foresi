<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Banking\Application;

/**
 * Port: the one operator mailbox that bank mail arrives in. Handled messages
 * are moved out of the inbox into Foresi/Done, Foresi/Unknown or
 * Foresi/Rejected, never deleted and never only marked seen, so an operator
 * who opens the mailbox loses nothing. A message is moved only after it was
 * safely stored; one left in the inbox is simply fetched again.
 */
interface InboundMailbox
{
    /**
     * The oldest messages of the inbox, oldest first, whole and unchanged,
     * leaving out the UIDs given, which the poller gave up on: they stay in the
     * inbox for the operator and never fill a batch again. Each call opens a
     * fresh session; the marks below apply to its messages.
     *
     * @param list<string> $skip UIDs to leave out
     *
     * @return list<MailboxItem>
     */
    public function fetch(int $limit, array $skip = []): array;

    /** Stored, or stored before: the message is done with. */
    public function markDone(string $uid): void;

    /** No inbound address of this server: nothing was kept. */
    public function markUnknown(string $uid): void;

    /** Too large or not a mail message: nothing was kept. */
    public function markRejected(string $uid): void;
}
