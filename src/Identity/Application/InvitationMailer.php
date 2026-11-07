<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Identity\Application;

use App\Identity\Domain\Invitation;
use App\Shared\Application\SystemMailUnavailable;

/**
 * Sends the link of an invitation to the invited person.
 */
interface InvitationMailer
{
    public function isConfigured(): bool;

    /**
     * @throws SystemMailUnavailable when the installation cannot send mail
     */
    public function send(Invitation $invitation, string $token, string $inviter, string $organisation): void;

    /**
     * The absolute link the person opens.
     */
    public function link(string $token): string;
}
