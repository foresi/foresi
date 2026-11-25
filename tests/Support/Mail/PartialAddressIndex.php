<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\Support\Mail;

use Doctrine\DBAL\Connection;

/**
 * The test schema comes from the mapping, and the schema tool of SQLite leaves
 * the condition off the unique index of a person's active address, so it is
 * written the way the Mail migration writes it (one active address per person
 * and purpose; a revoked one and a bank feed are not in it).
 */
final class PartialAddressIndex
{
    private function __construct()
    {
    }

    public static function apply(Connection $connection): void
    {
        $connection->executeStatement('DROP INDEX IF EXISTS uniq_mail_inbound_address_owner_purpose');
        $connection->executeStatement('CREATE UNIQUE INDEX uniq_mail_inbound_address_owner_purpose ON mail_inbound_address (owner_user_id, purpose) WHERE revoked_at IS NULL AND owner_user_id IS NOT NULL');
    }
}
