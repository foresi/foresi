<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Invoicing\Application;

/**
 * A message did not go out. A permanent refusal is not retried. The reason is
 * safe to store and show: an SMTP code and a fixed phrase, never the server's own
 * text, which may echo the username or more. No previous exception is kept, so
 * that text cannot travel with this one into a queue or a log.
 *
 * A settings problem is one the firm most likely fixes in its e-mail settings:
 * a refused login, a failed secure connection or certificate, a host that is not
 * allowed, or a password the configured keys cannot open.
 */
final class MailRefused extends \RuntimeException
{
    public function __construct(
        public readonly bool $permanent,
        public readonly string $safeReason,
        public readonly bool $settingsProblem = false,
    ) {
        parent::__construct($safeReason);
    }
}
