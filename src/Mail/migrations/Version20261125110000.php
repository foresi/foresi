<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Mail\Migrations;

use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Types\Types;
use Doctrine\Migrations\AbstractMigration;

/**
 * The daily limit per address (the day it was reached and how many mails it
 * dropped), the indexes the count and the duplicate check by bytes read by, and
 * the time after which a raw file may be purged. The condition of the partial index
 * is written in postUp() on SQLite.
 */
final class Version20261125110000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Daily limit per inbound address, mail message indexes and purge time';
    }

    public function up(Schema $schema): void
    {
        $address = $schema->getTable('mail_inbound_address');
        $address->addColumn('limit_reached_on', Types::DATE_IMMUTABLE, ['notnull' => false]);
        $address->addColumn('dropped_on_limit', Types::INTEGER, ['default' => 0]);

        $message = $schema->getTable('mail_message');
        $message->addColumn('purge_after', Types::DATETIME_IMMUTABLE, ['notnull' => false]);
        $message->addIndex(['address_id', 'received_at'], 'idx_mail_message_address_received');
        $message->addIndex(['message_id_header', 'raw_sha256'], 'idx_mail_message_id_bytes');
        $message->addIndex(['purge_after'], 'idx_mail_message_purge_after', [], ['where' => '(purge_after IS NOT NULL)']);
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
        $this->connection->executeStatement('DROP INDEX idx_mail_message_purge_after');
        $this->connection->executeStatement('CREATE INDEX idx_mail_message_purge_after ON mail_message (purge_after) WHERE purge_after IS NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX idx_mail_message_purge_after');
        $this->addSql('DROP INDEX idx_mail_message_id_bytes');
        $this->addSql('DROP INDEX idx_mail_message_address_received');
        $this->addSql('ALTER TABLE mail_message DROP purge_after');
        $this->addSql('ALTER TABLE mail_inbound_address DROP dropped_on_limit');
        $this->addSql('ALTER TABLE mail_inbound_address DROP limit_reached_on');
    }
}
