<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Party\Application;

use App\Party\Domain\FirmBankAccount;
use App\Party\Domain\LegalEntity;

/**
 * The bank accounts of the firms of the active tenant. Lists put the primary
 * account first and then follow the position. Call add() and makePrimary()
 * inside TransactionalWork, which flushes.
 */
interface FirmBankAccounts
{
    /**
     * @return list<FirmBankAccount>
     */
    public function of(LegalEntity $firm, bool $withArchived = false): array;

    public function primaryOf(LegalEntity $firm): ?FirmBankAccount;

    /**
     * The IBANs of the active accounts, in list order.
     *
     * @return list<string>
     */
    public function ibansOf(LegalEntity $firm): array;

    /**
     * @throws UnknownFirmBankAccount also for an account of another tenant
     */
    public function get(string $id): FirmBankAccount;

    /**
     * Archived accounts included: an IBAN is there once per firm.
     *
     * @param string $iban normalised, see Iban
     */
    public function ofIban(LegalEntity $firm, string $iban): ?FirmBankAccount;

    /**
     * The position after every account the firm has, archived ones included.
     */
    public function nextPosition(LegalEntity $firm): int;

    public function add(FirmBankAccount $account): void;

    /**
     * Makes the account the firm's primary one and demotes the previous one. The
     * demotion is written first: the unique index would refuse two primaries
     * within one flush whatever the order of the statements.
     */
    public function makePrimary(FirmBankAccount $account): void;
}
