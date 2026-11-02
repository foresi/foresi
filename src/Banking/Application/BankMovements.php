<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Banking\Application;

use App\Banking\Domain\BankMovement;

/**
 * The bank movements of the active tenant. Call the writing methods inside
 * TransactionalWork, which flushes.
 */
interface BankMovements
{
    /**
     * @throws UnknownBankMovement also for a movement of another tenant
     */
    public function get(string $id): BankMovement;

    /**
     * Takes the row of the movement for this transaction and gives it as stored now.
     * Unlike an invoice lock it keeps the session: a cancelled payment waiting to be
     * stored stays waiting. Call it inside TransactionalWork.
     *
     * @throws UnknownBankMovement also for a movement of another tenant
     */
    public function lock(string $id): BankMovement;

    /**
     * Takes the row of the firm's bank account for this transaction, so two
     * movements of one account are recorded one after the other and the second sees
     * the first. Call it inside TransactionalWork.
     */
    public function lockAccount(string $accountId): void;

    public function add(BankMovement $movement): void;

    /**
     * Whether a movement with the fingerprint came in another message, or in none.
     *
     * @param string|null $inboundMessageId the message the new one comes in; its own movements do not count
     */
    public function fingerprintTakenByOther(string $fingerprint, ?string $inboundMessageId): bool;

    /**
     * Whether a payment that is not cancelled came from the movement, whichever path
     * recorded it. The database allows one such payment per movement, so a save that
     * failed while this holds lost to another pairing.
     */
    public function hasLivePayment(string $movementId): bool;

    /**
     * Whether a movement in the account's currency is recorded on the account. One in
     * another currency says the account's currency is wrong, so it does not count.
     */
    public function anyOnAccount(string $accountId): bool;
}
