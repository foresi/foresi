<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Work\Infrastructure\Messenger;

use App\Work\Application\NotificationMailing;
use App\Work\Application\SendNotificationMail;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Sends the delayed e-mail of a mention or an assignment, in its tenant,
 * when it is still news; see NotificationMailing.
 */
#[AsMessageHandler]
final readonly class SendNotificationMailHandler
{
    public function __construct(private NotificationMailing $mailing)
    {
    }

    public function __invoke(SendNotificationMail $mail): void
    {
        $this->mailing->sendImmediate($mail);
    }
}
