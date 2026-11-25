<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Mail\Contract;

use Foresi\Shared\Contract\Refused;

/**
 * The inbound addresses of the signed-in person, for a module that shows them
 * on its own screen: the same rules as the person's REST and MCP calls (one
 * address per purpose that is not revoked, a purpose that has owners only,
 * someone else's address is not found, guests are refused). A refusal carries
 * a stable reason, such as address_exists, label_required, label_too_long,
 * address_revoked, not_found, forbidden, inbound_mail_not_configured or
 * inbound_mail_no_key; show your own text for it, not its message.
 *
 * Never log an address.
 */
interface OwnAddresses
{
    /**
     * The person's address of the purpose that is not revoked, if any.
     *
     * @throws Refused when nobody, or a guest, is signed in
     *
     * @phpstan-impure
     */
    public function mine(string $purpose): ?OwnAddress;

    /**
     * @throws Refused
     */
    public function create(string $purpose, string $label): IssuedAddress;

    /**
     * @throws Refused
     */
    public function rename(string $addressId, string $label): OwnAddress;

    /**
     * Revokes the address and issues a new one with the same purpose and label,
     * in one transaction: the old address stops at once. The new one has a new id.
     *
     * @throws Refused
     */
    public function replace(string $addressId): IssuedAddress;

    /**
     * @throws Refused
     */
    public function revoke(string $addressId): void;

    /**
     * The person's own address again, from its sealed copy; null when its
     * purpose does not show addresses again or the address is revoked.
     *
     * @throws Refused          when it is not the person's address
     * @throws AddressNotIssued when the server lost its address template or its key
     */
    public function reveal(string $addressId): ?string;
}
