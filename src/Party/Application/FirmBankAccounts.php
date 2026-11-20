<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Party\Application;

use Foresi\Party\Domain\FirmBankAccount;

/**
 * The bank accounts of the instance's firm. Lists put the primary
 * account first and then follow the position. Call add() and makePrimary()
 * inside TransactionalWork, which flushes.
 */
interface FirmBankAccounts
{
    /**
     * @return list<FirmBankAccount>
     */
    public function all(bool $withArchived = false): array;

    public function primary(): ?FirmBankAccount;

    /**
     * The IBANs of the active accounts, in list order.
     *
     * @return list<string>
     */
    public function ibans(): array;

    /**
     * @throws UnknownFirmBankAccount also for an unknown account
     */
    public function get(string $id): FirmBankAccount;

    /**
     * Takes the row of the account for this transaction and gives it as stored now,
     * the lock a movement is recorded under too, so a change that depends on the
     * account's movements sees every one of them. Call it inside TransactionalWork.
     *
     * @throws UnknownFirmBankAccount also for an unknown account
     */
    public function lock(string $id): FirmBankAccount;

    /**
     * Archived accounts included: an IBAN is there once.
     *
     * @param string $iban normalised, see Iban
     */
    public function ofIban(string $iban): ?FirmBankAccount;

    /**
     * The position after every account, archived ones included.
     */
    public function nextPosition(): int;

    public function add(FirmBankAccount $account): void;

    /**
     * Makes the account the primary one and demotes the previous one. The
     * demotion is written first: the unique index would refuse two primaries
     * within one flush whatever the order of the statements.
     */
    public function makePrimary(FirmBankAccount $account): void;
}
