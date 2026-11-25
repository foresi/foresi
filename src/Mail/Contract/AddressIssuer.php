<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Mail\Contract;

/**
 * Hands out the inbound addresses of a purpose. The secret token is drawn here
 * and leaves only inside the address returned: what is stored is its hash and,
 * for a purpose that shows its addresses again, a sealed copy. Never log an
 * address. Joins the transaction of the caller when one is open.
 */
interface AddressIssuer
{
    /**
     * @param string $purpose the key of a registered InboundPurpose
     *
     * @throws AddressNotIssued
     */
    public function issue(string $purpose, ?string $ownerUserId, string $label): IssuedAddress;

    /**
     * A new token on the same address row; the old address stops at once.
     *
     * @throws AddressNotIssued
     */
    public function rotate(string $addressId): IssuedAddress;

    /**
     * The address again, opened from its sealed copy; null for a purpose that
     * does not show its addresses again, and for a revoked or unknown one.
     *
     * @throws AddressNotIssued when the server lost its address template or its key
     */
    public function reveal(string $addressId): ?string;

    /**
     * The address takes in nothing from now on.
     */
    public function revoke(string $addressId): void;

    /**
     * Why no address of the purpose can be issued on this server; null when one can.
     */
    public function unavailable(string $purpose): ?AddressUnavailable;

    /**
     * The text with the token of every inbound address cut out, so a message that
     * quotes its own address shows it to nobody.
     */
    public function masked(string $text): string;
}
