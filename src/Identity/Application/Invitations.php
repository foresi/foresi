<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Identity\Application;

use App\Identity\Domain\Invitation;

/**
 * The invitations of the active tenant, and the one lookup across tenants: which
 * organisation a link token belongs to, before any organisation is active.
 */
interface Invitations
{
    public function add(Invitation $invitation): void;

    public function find(string $id): ?Invitation;

    public function byTokenHash(string $tokenHash): ?Invitation;

    /**
     * @return list<Invitation> the pending invitations of the address
     */
    public function pendingFor(string $email, \DateTimeImmutable $now): array;

    /**
     * @return list<Invitation> every pending invitation, the one that expires first first
     */
    public function pending(\DateTimeImmutable $now): array;

    public function tenantOfToken(string $tokenHash): ?InvitationTenant;

    public function flush(): void;
}
