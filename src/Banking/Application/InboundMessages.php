<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Banking\Application;

use App\Banking\Domain\InboundMessage;
use App\Banking\Domain\InboundStatus;

/**
 * The inbound messages of the active tenant.
 */
interface InboundMessages
{
    /**
     * Whether a message with this dedup key is stored already.
     */
    public function has(string $dedupKey): bool;

    /**
     * Stores a new message by a single statement, in the active tenant. Of two
     * messages with one dedup key only the first is stored, even at the same
     * moment; the message object is not managed afterwards.
     *
     * @return bool false when a message with its dedup key is stored already
     */
    public function add(InboundMessage $message): bool;

    /**
     * The message as stored now, or null for none in the active tenant.
     */
    public function find(string $id): ?InboundMessage;

    /**
     * Takes the message for processing by a single statement, when it stands in
     * one of the statuses (and, when given, has the failure). Call it inside the
     * transaction that processes it: its row stays locked to the end, so of two
     * workers on one message the second waits and then finds it taken, and a
     * worker that dies leaves it as it was.
     *
     * @param non-empty-list<InboundStatus> $from
     *
     * @return ClaimedMessage|null null when the message is of another tenant, or stands elsewhere
     */
    public function claim(string $id, array $from, ?string $failure = null): ?ClaimedMessage;

    /**
     * Stores where processing left the claimed message and counts the attempt.
     */
    public function finish(string $id, ProcessingResult $result, \DateTimeImmutable $at): void;

    /**
     * Counts an attempt that failed unexpectedly, after its transaction was undone.
     */
    public function countFailedAttempt(string $id): void;

    /**
     * Failed or unrecognised messages that a parser of today may read: failed ones
     * of a parser whose version went up since, and ones read under another set of
     * parsers. Oldest first.
     *
     * @param array<string, int> $parserVersions the current version of every parser, by id
     * @param string             $signature      see BankMessageParsers::signature()
     *
     * @return list<string> their ids
     */
    public function due(array $parserVersions, string $signature, int $limit): array;

    /**
     * How many messages wait for someone: failed or rejected ones, and ones still
     * waiting for processing since before the moment given, which the queue gave up on.
     */
    public function needingAttention(\DateTimeImmutable $stuckBefore, ?string $firmId = null, ?string $accountId = null): int;

    /**
     * How many messages came to the accounts the filter names.
     */
    public function count(MovementFilter $filter): int;

    /**
     * The messages that came to the accounts the filter names, newest first.
     *
     * @return list<MessageRow>
     */
    public function rows(MovementFilter $filter, \DateTimeImmutable $stuckBefore, int $offset, int $limit): array;

    /**
     * The message as the Messages tab lists it, or null for none in the active tenant.
     */
    public function row(string $id, \DateTimeImmutable $stuckBefore): ?MessageRow;

    /**
     * Sets the message aside by a single statement when it stands in one of the
     * statuses, and writes who did it to the audit log in the same transaction. Call
     * it inside TransactionalWork. The raw message stays stored.
     *
     * @param non-empty-list<InboundStatus> $from
     * @param \DateTimeImmutable|null       $receivedBefore a message still waiting is set aside only when it came before this
     *
     * @return InboundStatus|null the status it stood in, or null when it stood elsewhere
     */
    public function dismiss(string $id, array $from, ?\DateTimeImmutable $receivedBefore): ?InboundStatus;
}
