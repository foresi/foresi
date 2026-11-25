<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Mail\Contract;

/**
 * What an inbound address is for, such as the payment notices of a bank feed:
 * the consumer a stored message is handed to. A module registers one by
 * implementing this interface (tagged foresi.mail.inbound_purpose); an address
 * whose purpose nobody registered takes in nothing.
 */
interface InboundPurpose
{
    public const string TAG = 'foresi.mail.inbound_purpose';

    /** The stable key stored on every address of the purpose, at most 40 characters. */
    public function key(): string;

    /** A short English name, for the operator. */
    public function label(): string;

    /** Whether every address of the purpose belongs to one person. */
    public function ownerRequired(): bool;

    /** Whether the address can be shown again after it was issued, from a sealed copy of its token. */
    public function reshowable(): bool;

    /**
     * Takes the stored message. Runs inside the transaction that stored it: an
     * exception undoes the row and removes the raw file. Throw NotAccepted to
     * refuse the message as if the address were unknown.
     *
     * @throws NotAccepted
     */
    public function accept(string $messageId, string $addressId): void;
}
