<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Inbox;

use Foresi\Shared\Infrastructure\Module\ModuleBundle;

/**
 * A person's own inbox: the mail that arrives at their inbox address, each an
 * item matched to the sender's contact and seen by its owner alone.
 */
final class InboxBundle extends ModuleBundle
{
}
