<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Work\Application;

/**
 * The e-mail of a mention or of becoming responsible, sent a short while after
 * the event, unless the inbox row was read, done or mailed meanwhile (Linear
 * skips what was read).
 */
final readonly class SendNotificationMail
{
    public function __construct(
        public string $notificationId,
        public string $kind,
        public string $actorId,
        public ?string $commentId = null,
    ) {
    }
}
