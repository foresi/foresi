<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Invoicing\Application;

/**
 * No configured key seals or opens a mail server password. The message never
 * holds a key or a secret.
 */
final class MailSecretsUnavailable extends \RuntimeException
{
}
