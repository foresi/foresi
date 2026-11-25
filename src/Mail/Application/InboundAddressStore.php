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

    public function add(InboundAddress $address): void;

    /**
     * Records that mail arrived for the address, by plain SQL: no audit entry.
     * Never moves the time back.
     */
    public function touch(string $id, \DateTimeImmutable $at): void;
}
