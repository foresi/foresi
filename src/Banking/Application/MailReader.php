<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Banking\Application;

/**
 * Reads a raw RFC 5322 message, as fetched from the mailbox or posted to the
 * webhook, into an InboundMail.
 */
interface MailReader
{
    /**
     * @throws \InvalidArgumentException when the bytes are not a mail message
     */
    public function read(string $raw): InboundMail;
}
