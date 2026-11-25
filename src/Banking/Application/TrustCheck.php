<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Banking\Application;

use Foresi\Banking\Contract\MovementTrust;

/**
 * What the owner needs to set up trust, of the last message an account got:
 * the channel it came by, the authserv-id of its topmost Authentication-Results,
 * whether that server is trusted for the channel, and the verdict. Nothing else
 * of the mail.
 */
final readonly class TrustCheck
{
    /**
     * @param string      $channel    imap or webhook, as Mail names it
     * @param string|null $authservId the authserv-id of the topmost Authentication-Results, or null for none or an unreadable mail
     * @param string|null $basis      one of the TrustVerdict constants, or null while the message waits
     */
    public function __construct(
        public string $channel,
        public ?string $authservId,
        public bool $trusted,
        public ?MovementTrust $trust,
        public ?string $basis,
    ) {
    }
}
