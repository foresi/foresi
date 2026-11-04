<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Tests\Support\Mail;

use App\Banking\Application\InboundMailbox;
use App\Banking\Application\MailboxItem;

/**
 * The operator mailbox in memory: an inbox and the three folders handled mail
 * is moved to. UIDs grow in arrival order, so the oldest message has the lowest.
 * A move can be told to fail once, as when the connection drops after the
 * message was stored.
 */
final class FakeInboundMailbox implements InboundMailbox
{
    public const string DONE = 'Foresi/Done';
    public const string UNKNOWN = 'Foresi/Unknown';
    public const string REJECTED = 'Foresi/Rejected';

    /** @var array<int, string> raw messages by UID */
    private array $inbox = [];

    /** @var array<string, list<int>> UIDs moved, by folder */
    private array $moved = [self::DONE => [], self::UNKNOWN => [], self::REJECTED => []];

    private int $nextUid = 1;

    /** @var list<int> */
    private array $limits = [];

    /** @var array<int, true> */
    private array $failMove = [];

    /** @var array<int, true> */
    private array $failEveryMove = [];

    public function deliver(string $raw): int
    {
        $uid = $this->nextUid++;
        $this->inbox[$uid] = $raw;

        return $uid;
    }

    /**
     * Places a message with a chosen UID, so a test can deliver out of order.
     */
    public function deliverAs(int $uid, string $raw): void
    {
        $this->inbox[$uid] = $raw;
        $this->nextUid = max($this->nextUid, $uid + 1);
    }

    public function failNextMoveOf(int $uid): void
    {
        $this->failMove[$uid] = true;
    }

    /**
     * Every move of the message fails, as on a server that refuses it.
     */
    public function failEveryMoveOf(int $uid): void
    {
        $this->failEveryMove[$uid] = true;
    }

    public function fetch(int $limit, array $skip = []): array
    {
        $this->limits[] = $limit;
        $uids = array_values(array_diff(array_keys($this->inbox), array_map(intval(...), $skip)));
        sort($uids);

        return array_map(fn (int $uid): MailboxItem => new MailboxItem((string) $uid, $this->inbox[$uid]), \array_slice($uids, 0, $limit));
    }

    public function markDone(string $uid): void
    {
        $this->move($uid, self::DONE);
    }

    public function markUnknown(string $uid): void
    {
        $this->move($uid, self::UNKNOWN);
    }

    public function markRejected(string $uid): void
    {
        $this->move($uid, self::REJECTED);
    }

    /**
     * @return list<int>
     */
    public function inbox(): array
    {
        $uids = array_keys($this->inbox);
        sort($uids);

        return $uids;
    }

    /**
     * @return list<int>
     */
    public function movedTo(string $folder): array
    {
        return $this->moved[$folder];
    }

    /**
     * @return list<int> the limit of every fetch, in order
     */
    public function limits(): array
    {
        return $this->limits;
    }

    private function move(string $uid, string $folder): void
    {
        $key = (int) $uid;
        if (!isset($this->inbox[$key])) {
            throw new \LogicException(\sprintf('No message %s is in the inbox.', $uid));
        }
        if (isset($this->failMove[$key])) {
            unset($this->failMove[$key]);

            throw new \RuntimeException('The connection dropped.');
        }
        if (isset($this->failEveryMove[$key])) {
            throw new \RuntimeException('The server refused the move.');
        }
        unset($this->inbox[$key]);
        $this->moved[$folder][] = $key;
    }
}
