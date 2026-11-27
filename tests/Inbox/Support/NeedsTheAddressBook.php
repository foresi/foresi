<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\Inbox\Support;

use Foresi\Contacts\ContactsBundle;

/**
 * The inbox matches senders to contacts and makes contacts only when the
 * contacts module is installed (an optional dependency); a test that needs a
 * contact is skipped where it is not, as in the module subset of the inbox.
 */
trait NeedsTheAddressBook
{
    protected static function needsTheAddressBook(): void
    {
        if (!class_exists(ContactsBundle::class)) {
            self::markTestSkipped('The contacts module is not installed, so the inbox has no contacts.');
        }
    }
}
