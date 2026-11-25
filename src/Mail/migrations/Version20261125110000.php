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
 * The daily limit per address (the day it was reached and how many mails it
 * dropped), the indexes the count and the duplicate check by bytes read by, and
 * the time after which a raw file may be purged. Plain SQL, in this order, as the
 * partial index needs its column and both PostgreSQL and SQLite know it.
 */
final class Version20261125110000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Daily limit per inbound address, mail message indexes and purge time';
    }

    public function up(Schema $schema): void
    {
        $timestamp = $this->connection->getDatabasePlatform() instanceof PostgreSQLPlatform ? 'TIMESTAMP(0) WITHOUT TIME ZONE' : 'DATETIME';
        $this->addSql('ALTER TABLE mail_inbound_address ADD limit_reached_on DATE DEFAULT NULL');
        $this->addSql('ALTER TABLE mail_inbound_address ADD dropped_on_limit INT DEFAULT 0 NOT NULL');
        $this->addSql('ALTER TABLE mail_message ADD purge_after '.$timestamp.' DEFAULT NULL');
        $this->addSql('CREATE INDEX idx_mail_message_address_received ON mail_message (address_id, received_at)');
        $this->addSql('CREATE INDEX idx_mail_message_id_bytes ON mail_message (message_id_header, raw_sha256)');
        $this->addSql('CREATE INDEX idx_mail_message_purge_after ON mail_message (purge_after) WHERE purge_after IS NOT NULL');
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
