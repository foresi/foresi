<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Work\Application;

use App\Tenancy\Application\TenantScopedMessage;

/**
 * The e-mail of a mention or of becoming responsible, sent a short while after
 * the event in the tenant the message carries, unless the inbox row was read,
 * done or mailed meanwhile (Linear skips what was read).
 */
final readonly class SendNotificationMail implements TenantScopedMessage
{
    public function __construct(
        public string $notificationId,
        public string $kind,
        public string $actorId,
        public ?string $commentId = null,
    ) {
    }
}
