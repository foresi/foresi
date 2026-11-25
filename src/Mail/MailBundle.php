<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Mail;

use Foresi\Shared\Infrastructure\Module\ModuleBundle;

/**
 * Inbound mail: the operator mailbox and the webhook it comes in by, the
 * check of who sent it, and the files it is kept in.
 */
final class MailBundle extends ModuleBundle
{
}
