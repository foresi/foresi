<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Banking\Application;

use Foresi\Banking\Domain\BankMovement;
use Foresi\Shared\Domain\Currency;

/**
 * What pays documents from a movement, such as Invoicing with its invoices, kept
 * apart from the movement itself: PairMovement locks the movement, decides and
 * pairs it, and this records the payment and names the documents it paid. Without
 * such a module NoPaymentPairing answers, and movements stay unmatched.
 *
 * The locks go in one order everywhere: the movement first, then its documents by
 * ascending id.
 */
interface PaymentPairing
{
    /**
     * Whether movements can be paired at all; the screens offer pairing only then.
     */
    public function isAvailable(): bool;

    /**
     * Takes the rows of the documents for this transaction, in the order given.
     *
     * @param list<string> $documentIds ascending
     *
     * @throws PairingRefused with UNKNOWN_DOCUMENT
     */
    public function lock(array $documentIds): void;

    /**
     * Whether the document has nothing left to pay, or for an outgoing movement
     * nothing to refund, read under its lock.
     *
     * @throws PairingRefused with UNKNOWN_DOCUMENT
     */
    public function isSettled(string $documentId, bool $incoming): bool;

    /**
     * Records the payment of the movement over the documents, in the current
     * transaction, after the movement and the documents were locked.
     *
     * @param list<Allocation> $allocations none is refused, as parts that do not add up are
     *
     * @return string the payment's id
     *
     * @throws PairingRefused nothing is stored
     */
    public function pay(BankMovement $movement, array $allocations): string;

    /**
     * Cancels the payment; its movement is released in the same transaction.
     *
     * @throws PairingRefused with NO_PAIRING when nothing pairs
     */
    public function cancel(string $paymentId): void;

    /**
     * The most minor units the firm may write off on a document in the currency,
     * or null for no settling at all.
     */
    public function settlementLimit(Currency $currency): ?int;

    /**
     * Whether a payment that is not cancelled came from the movement, whichever path
     * recorded it. One such payment per movement is allowed, so a save that failed
     * while this holds lost to another pairing.
     */
    public function isLive(string $movementId): bool;

    /**
     * The numbers of the documents each payment pays, in one read for all of them.
     *
     * @param list<string> $paymentIds
     *
     * @return array<string, array<string, string>> number by document id, by payment id
     */
    public function documentsOf(array $paymentIds): array;

    /**
     * Where a person opens a document documentsOf() named.
     */
    public function documentUrl(string $documentId): string;
}
