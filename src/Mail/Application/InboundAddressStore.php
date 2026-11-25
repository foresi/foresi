<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Mail\Application;

use Foresi\Mail\Domain\InboundAddress;

/**
 * The inbound addresses. Call add() inside MailTransaction, which flushes.
 */
interface InboundAddressStore
{
    /**
     * The address mail is routed to by the hash of its token, or null when no
     * address that is not revoked has it.
     *
     * @param string $tokenHash see InboundToken::hash()
     */
    public function routable(string $tokenHash): ?InboundAddress;

    public function get(string $id): ?InboundAddress;

    /**
     * The addresses of a person, oldest first, revoked ones too; of one purpose when given.
     *
     * @return list<InboundAddress>
     */
    public function ownedBy(string $ownerUserId, ?string $purpose = null): array;

    /**
     * The address of the person for the purpose that is not revoked, if any.
     */
    public function activeOf(string $ownerUserId, string $purpose): ?InboundAddress;

    public function add(InboundAddress $address): void;

    /**
     * Writes what changed so far inside the open transaction; the partial unique
     * index of a person's active address needs the old one revoked before the
     * new one is inserted, and the ORM inserts first.
     *
     * @throws AddressAlreadyActive when it breaks that index
     */
    public function flush(): void;

    /**
     * Records that mail arrived for the address, by plain SQL: no audit entry.
     * Never moves the time back.
     */
    public function touch(string $id, \DateTimeImmutable $at): void;
}
