<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Banking\Application;

use Foresi\Banking\Domain\ReceivedStatement;

/**
 * The bank statements that came in. Call add() inside TransactionalWork, which flushes.
 */
interface ReceivedStatements
{
    public function add(ReceivedStatement $statement): void;

    /**
     * The statements of one account and source, as stored now.
     *
     * @return list<ReceivedStatement>
     */
    public function chainOf(string $accountId, string $sourceId): array;

    /**
     * Every statement, of every account.
     *
     * @return list<ReceivedStatement>
     */
    public function all(): array;
}
