<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Mail\Contract;

/**
 * An attachment of a stored message, without its bytes. The name and the type
 * are the sender's word. `inline` says the sender embedded the part in the body
 * (`Content-Disposition: inline`, or no disposition and a `Content-ID`), as a
 * logo or a picture in a signature is; it only reports, what to do is the
 * consumer's call.
 */
final readonly class MailAttachment
{
    public function __construct(
        public int $index,
        public string $name,
        public string $contentType,
        public int $size,
        public bool $inline = false,
        public ?string $contentId = null,
    ) {
    }
}
