<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Work\Application;

/**
 * Hands the e-mail of a direct event to the worker, to be sent after a short
 * delay. Losing it costs only the e-mail: the inbox has the row.
 */
interface NotificationMailQueue
{
    public function queue(SendNotificationMail $mail): void;
}
