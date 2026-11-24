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
 * back and removes the Mail rows of the bank feeds.
 */
final class Version20261124160002 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Copy the bank feeds and the bank mail into the Mail module';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            INSERT INTO mail_inbound_address (id, purpose, owner_user_id, label, token_hash, token_sealed, created_at, revoked_at, last_received_at)
            SELECT id, 'bank_feed', NULL, 'Bank feed', token_hash, token_sealed, created_at, NULL, last_received_at FROM bank_feed
            SQL);
        $this->addSql('UPDATE bank_feed SET mail_inbound_address_id = id');
        $this->addSql(<<<'SQL'
            INSERT INTO mail_message (id, address_id, channel, dedup_key, raw_key, raw_sha256, size, message_id_header, in_reply_to, references_header,
                from_address, subject, sent_at, received_at, verdict, verdict_basis)
            SELECT id, bank_feed_id, channel, dedup_key, raw_key, raw_sha256, size, NULL, NULL, NULL,
                from_address, subject, NULL, received_at, 'unknown', NULL FROM inbound_message
            SQL);
        $this->addSql('UPDATE inbound_message SET mail_message_id = id');
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
        $this->addSql('UPDATE inbound_message SET mail_message_id = NULL');
        $this->addSql('UPDATE bank_feed SET mail_inbound_address_id = NULL');
        $this->addSql("DELETE FROM mail_message WHERE address_id IN (SELECT id FROM mail_inbound_address WHERE purpose = 'bank_feed')");
        $this->addSql("DELETE FROM mail_inbound_address WHERE purpose = 'bank_feed'");
    }
}
