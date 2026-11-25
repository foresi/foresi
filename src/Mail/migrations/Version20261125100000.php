<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Mail\Migrations;

use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * One active address per person and purpose: a partial unique index, so two
 * requests at once cannot make two. Revoked addresses and the ones without an
 * owner (a bank feed) are not in it. On SQLite the condition is written in postUp().
 */
final class Version20261125100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'One active inbound address per person and purpose';
    }

    public function up(Schema $schema): void
    {
        $schema->getTable('mail_inbound_address')->addUniqueIndex(
            ['owner_user_id', 'purpose'],
            'uniq_mail_inbound_address_owner_purpose',
            ['where' => '((revoked_at IS NULL) AND (owner_user_id IS NOT NULL))'],
        );
    }

    /**
     * DBAL writes the condition of a partial index on PostgreSQL only; SQLite knows
     * the same index, so it is written again there with its condition.
     */
    public function postUp(Schema $schema): void
    {
        if ($this->connection->getDatabasePlatform() instanceof PostgreSQLPlatform) {
            return;
        }
        $this->connection->executeStatement('DROP INDEX uniq_mail_inbound_address_owner_purpose');
        $this->connection->executeStatement('CREATE UNIQUE INDEX uniq_mail_inbound_address_owner_purpose ON mail_inbound_address (owner_user_id, purpose) WHERE revoked_at IS NULL AND owner_user_id IS NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $schema->getTable('mail_inbound_address')->dropIndex('uniq_mail_inbound_address_owner_purpose');
    }
}
