<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Purchasing\Application\Mail;

/**
 * Hands a mail to the background job. Called inside the transaction that
 * stores the mail, on a queue kept in the same database, so the job exists only
 * if the mail does.
 */
interface MailFilingQueue
{
    public function queue(FileMailAttachments $message): void;
}
