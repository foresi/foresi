<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Purchasing\Application\Mail;

/**
 * Asks the background job to turn the attachments of one stored mail into
 * received-document drafts.
 */
final readonly class FileMailAttachments
{
    public function __construct(public string $messageId)
    {
    }
}
