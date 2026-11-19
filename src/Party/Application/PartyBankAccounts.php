<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Party\Application;

use Foresi\Party\Domain\PartyBankAccount;

/**
 * Adds and removes bank accounts of parties and keeps at most one of them
 * primary. Adding and making primary own their unit of work: they change and flush inside one
 * transaction, so a failure after the old primary was demoted rolls that back.
 */
interface PartyBankAccounts
{
    /**
     * A primary account demotes the party's previous primary account.
     */
    public function add(PartyBankAccount $account): void;

    public function makePrimary(PartyBankAccount $account): void;

    /**
     * Deletes the account; the surrounding unit of work flushes.
     */
    public function remove(PartyBankAccount $account): void;
}
