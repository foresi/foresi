<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Shared\Application;

/**
 * Sends mail of the installation, such as invitations, through the operator's
 * system mailer, asynchronously. Never a firm's own server.
 */
interface SystemMailer
{
    /**
     * Queues the mail.
     *
     * @throws SystemMailUnavailable     when the installation has no system mailer
     * @throws \InvalidArgumentException when the recipient is not an e-mail address
     */
    public function send(SystemMail $mail): void;

    /**
     * Whether the installation has a system mailer and a sender.
     */
    public function isConfigured(): bool;
}
