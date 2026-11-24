<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Mail\Application;

use Foresi\Mail\Contract\MailAttachment;
use Foresi\Mail\Contract\MailAttachmentContent;

/**
 * The attachments of a raw RFC 5322 message, read when asked for.
 */
interface MailParts
{
    /**
     * @return list<MailAttachment> in message order
     *
     * @throws \InvalidArgumentException when the bytes are not a mail message
     */
    public function attachments(string $raw): array;

    /**
     * @throws \InvalidArgumentException when the bytes are not a mail message
     * @throws \OutOfRangeException      when the message has no attachment at the index
     */
    public function attachment(string $raw, int $index): MailAttachmentContent;
}
