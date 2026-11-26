<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Identity\Application;

use Foresi\Identity\Domain\User;

/**
 * The two mails of an e-mail change: the link to the new address, which proves
 * the person reads it, and afterwards the notice to the old one, so a change
 * made in their place does not go unnoticed (OWASP: notify the old address).
 */
interface EmailChangeMail
{
    /** Without mail the link cannot reach the new address, so no change starts. */
    public function isConfigured(): bool;

    public function sendConfirmation(User $user, string $newEmail, #[\SensitiveParameter] string $token, \DateTimeImmutable $expiresAt): void;

    /**
     * @param list<string> $owners the names of the firm's owners to turn to if it was not the person
     */
    public function sendNotice(User $user, string $oldEmail, \DateTimeImmutable $changedAt, array $owners): void;
}
