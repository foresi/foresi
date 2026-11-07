<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Shared\Application;

/**
 * The installation has no system mailer (SYSTEM_MAILER_DSN) or no sender
 * (SYSTEM_MAIL_FROM). The message can be shown as it is.
 */
final class SystemMailUnavailable extends \RuntimeException
{
    public function __construct()
    {
        parent::__construct('This installation cannot send e-mail yet.');
    }
}
