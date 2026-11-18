<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Invoicing\Application;

use Foresi\Invoicing\Domain\FirmMailServer;

/**
 * Hands a message to a firm's mail server. Returning means the server accepted
 * it. Tests and the end-to-end environment use a recording fake: they never send.
 */
interface MailGateway
{
    /**
     * @throws MailRefused
     */
    public function send(MailMessage $message, FirmMailServer $server): void;
}
