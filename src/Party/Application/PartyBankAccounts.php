<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Party\Application;

use App\Party\Domain\PartyBankAccount;

/**
 * Adds bank accounts to parties and keeps at most one of them primary. Both
 * operations own their unit of work: they change and flush inside one
 * transaction, so a failure after the old primary was demoted rolls that back.
 */
interface PartyBankAccounts
{
    /**
     * A primary account demotes the party's previous primary account.
     */
    public function add(PartyBankAccount $account): void;

    public function makePrimary(PartyBankAccount $account): void;
}
