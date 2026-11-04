<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Banking\Application;

use App\Banking\Domain\BankFeed;

/**
 * The inbound addresses of the bank accounts of the active tenant. Call add()
 * inside TransactionalWork, which flushes.
 */
interface BankFeeds
{
    /**
     * Null for an account without an address, and for one of another tenant.
     */
    public function ofAccount(string $accountId): ?BankFeed;

    /**
     * The feeds of these accounts, keyed by account id; accounts without one are left out.
     *
     * @param list<string> $accountIds
     *
     * @return array<string, BankFeed>
     */
    public function ofAccounts(array $accountIds): array;

    public function add(BankFeed $feed): void;

    /**
     * The full address, opened from the sealed token. Show it to the firm only:
     * whoever knows it can post notifications as the bank.
     *
     * @throws AddressRefused with NotConfigured or NoKey
     */
    public function revealAddress(BankFeed $feed): string;

    /**
     * Records that mail arrived for the feed, by plain SQL: no audit entry, and
     * across tenants, as the inbox knows the feed by its token alone. Never
     * moves the time back.
     */
    public function touch(string $feedId, \DateTimeImmutable $at): void;
}
