<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Banking\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * The second step of the move into Mail, data only: every bank feed becomes a
 * Mail inbound address of the purpose bank_feed with the same id, token hash,
 * sealed token and times, and every bank mail a Mail message with the same id,
 * so raw files, movements and links keep working. What was never stored, the
 * Message-ID, the thread headers and the date the sender wrote, stays empty, and
 * the verdict of a mail stored before is unknown. Going down copies everything
 * back, gives a mail that reached two feeds a dedup key per row, and removes the
 * Mail rows of the bank feeds.
 */
final class Version20261124160002 extends AbstractMigration
{
    /** A row whose dedup key an earlier row holds already. */
    private const string LATER_COPY = 'EXISTS (SELECT 1 FROM inbound_message o WHERE o.dedup_key = inbound_message.dedup_key '
        .'AND (o.received_at < inbound_message.received_at OR (o.received_at = inbound_message.received_at AND o.id < inbound_message.id)))';

    public function getDescription(): string
    {
        return 'Copy the bank feeds and the bank mail into the Mail module';
    }

    public function up(Schema $schema): void
    {
        // The data moves in postUp(), once the Mail tables exist: nothing is read while migrations are planned.
    }

    public function postUp(Schema $schema): void
    {
        $this->connection->executeStatement(<<<'SQL'
            INSERT INTO mail_inbound_address (id, purpose, owner_user_id, label, token_hash, token_sealed, created_at, revoked_at, last_received_at)
            SELECT id, 'bank_feed', NULL, 'Bank feed', token_hash, token_sealed, created_at, NULL, last_received_at FROM bank_feed
            SQL);
        $this->connection->executeStatement('UPDATE bank_feed SET mail_inbound_address_id = id');
        $this->connection->executeStatement(<<<'SQL'
            INSERT INTO mail_message (id, address_id, channel, dedup_key, raw_key, raw_sha256, size, message_id_header, in_reply_to, references_header,
                from_address, subject, sent_at, received_at, verdict, verdict_basis)
            SELECT id, bank_feed_id, channel, dedup_key, raw_key, raw_sha256, size, NULL, NULL, NULL,
                from_address, subject, NULL, received_at, 'unknown', NULL FROM inbound_message
            SQL);
        $this->connection->executeStatement('UPDATE inbound_message SET mail_message_id = id');
    }

    public function down(Schema $schema): void
    {
        $columns = [];
        foreach (['channel', 'dedup_key', 'raw_key', 'raw_sha256', 'size', 'from_address', 'subject', 'received_at'] as $column) {
            $columns[] = $column.' = (SELECT mm.'.$column.' FROM mail_message mm WHERE mm.id = inbound_message.mail_message_id)';
        }
        $this->addSql('UPDATE inbound_message SET '.implode(', ', $columns));
        $this->addSql(<<<'SQL'
            UPDATE bank_feed SET
                token_hash = (SELECT a.token_hash FROM mail_inbound_address a WHERE a.id = bank_feed.mail_inbound_address_id),
                token_sealed = (SELECT a.token_sealed FROM mail_inbound_address a WHERE a.id = bank_feed.mail_inbound_address_id)
            SQL);
        // Mail dedups per address, Banking did across all feeds: of a mail that reached two
        // feeds, the earliest row keeps its key, a later one takes the hash of its bytes, and
        // one whose bytes are the same as well takes a key made of its own id.
        $this->addSql('UPDATE inbound_message SET dedup_key = raw_sha256 WHERE '.self::LATER_COPY);
        $this->addSql("UPDATE inbound_message SET dedup_key = REPLACE(CAST(id AS VARCHAR(36)), '-', '') || REPLACE(CAST(id AS VARCHAR(36)), '-', '') WHERE ".self::LATER_COPY);
        $this->addSql('UPDATE inbound_message SET mail_message_id = NULL');
        $this->addSql('UPDATE bank_feed SET mail_inbound_address_id = NULL');
        $this->addSql("DELETE FROM mail_message WHERE address_id IN (SELECT id FROM mail_inbound_address WHERE purpose = 'bank_feed')");
        $this->addSql("DELETE FROM mail_inbound_address WHERE purpose = 'bank_feed'");
    }
}
