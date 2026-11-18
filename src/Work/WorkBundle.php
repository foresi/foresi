<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Work;

use Foresi\Shared\Infrastructure\Module\ModuleBundle;
use Foresi\Work\Application\SendNotificationMail;

/**
 * Projects and their tasks, shared with guests, and the notifications about them.
 */
final class WorkBundle extends ModuleBundle
{
    protected function messageRouting(): array
    {
        return [
            // The delayed e-mail of a mention or an assignment; it queues system mail.
            SendNotificationMail::class => 'async',
        ];
    }
}
