<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Banking\Application;

use Foresi\Banking\Domain\BankMovement;
use Foresi\Shared\Application\View\ViewFilter;

/**
 * The bank movements. Call the writing methods inside TransactionalWork, which
 * flushes.
 */
interface BankMovements
{
    /**
     * @throws UnknownBankMovement for an unknown movement
     */
    public function get(string $id): BankMovement;

    /**
     * Takes the row of the movement for this transaction and gives it as stored now.
     * Unlike an invoice lock it keeps the session: a cancelled payment waiting to be
     * stored stays waiting. Call it inside TransactionalWork.
     *
     * @throws UnknownBankMovement for an unknown movement
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

    /**
     * How many incoming movements wait for a pairing.
     */
    public function unmatchedIncoming(): int;

    /**
     * Every bank account of the firms, archived ones too, by firm name and then
     * as the firm orders them.
     *
     * @return array<string, array{firmId: string, firmName: string, label: string, iban: string}> by account id
     */
    public function accounts(): array;

    /**
     * How many movements the filter finds within its view and text; a view of the
     * bank mail finds none.
     */
    public function count(MovementFilter $filter): int;

    /**
     * How many movements each view holds within the text, all in one query (spec D9).
     *
     * @param array<string, ViewFilter> $views by key, none of the bank mail
     *
     * @return array<string, int> by the same key
     */
    public function counts(string $text, array $views): array;

    /**
     * The movements the filter finds, newest booked first, each with the numbers of
     * the invoices its payment pays, read for the whole page at once.
     *
     * @return list<MovementRow>
     */
    public function rows(MovementFilter $filter, int $offset, int $limit): array;
}
