<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Mail\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Types\Types;
use Doctrine\Migrations\AbstractMigration;

/**
 * The baseline of the Mail module: the inbound addresses and the messages that
 * came to them. The bank mail stored before moves here in Banking's migration
 * that follows.
 */
final class Version20261124160000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create the mail inbound addresses and mail messages';
    }

    public function up(Schema $schema): void
    {
        $address = $schema->createTable('mail_inbound_address');
        $address->addColumn('id', Types::GUID);
        $address->addColumn('purpose', Types::STRING, ['length' => 40]);
        $address->addColumn('owner_user_id', Types::GUID, ['notnull' => false]);
        $address->addColumn('label', Types::STRING, ['length' => 80]);
        $address->addColumn('token_hash', Types::STRING, ['length' => 64, 'fixed' => true]);
        $address->addColumn('token_sealed', Types::TEXT, ['notnull' => false]);
        $address->addColumn('created_at', Types::DATETIME_IMMUTABLE);
        $address->addColumn('revoked_at', Types::DATETIME_IMMUTABLE, ['notnull' => false]);
        $address->addColumn('last_received_at', Types::DATETIME_IMMUTABLE, ['notnull' => false]);
        $address->setPrimaryKey(['id']);
        $address->addUniqueIndex(['token_hash'], 'uniq_mail_inbound_address_token');
        $address->addIndex(['owner_user_id'], 'idx_mail_inbound_address_owner');

        $message = $schema->createTable('mail_message');
        $message->addColumn('id', Types::GUID);
        $message->addColumn('address_id', Types::GUID);
        $message->addColumn('channel', Types::STRING, ['length' => 16]);
        $message->addColumn('dedup_key', Types::STRING, ['length' => 64, 'fixed' => true]);
        $message->addColumn('raw_key', Types::STRING, ['length' => 128]);
        $message->addColumn('raw_sha256', Types::STRING, ['length' => 64, 'fixed' => true]);
        $message->addColumn('size', Types::INTEGER);
        $message->addColumn('message_id_header', Types::STRING, ['length' => 255, 'notnull' => false]);
        $message->addColumn('in_reply_to', Types::STRING, ['length' => 255, 'notnull' => false]);
        // REFERENCES is a reserved word in SQL.
        $message->addColumn('references_header', Types::TEXT, ['notnull' => false]);
        $message->addColumn('from_address', Types::STRING, ['length' => 320, 'notnull' => false]);
        $message->addColumn('subject', Types::STRING, ['length' => 255]);
        $message->addColumn('sent_at', Types::DATETIME_IMMUTABLE, ['notnull' => false]);
        $message->addColumn('received_at', Types::DATETIME_IMMUTABLE);
        $message->addColumn('verdict', Types::STRING, ['length' => 16]);
        $message->addColumn('verdict_basis', Types::STRING, ['length' => 32, 'notnull' => false]);
        $message->setPrimaryKey(['id']);
        $message->addUniqueIndex(['address_id', 'dedup_key'], 'uniq_mail_message_dedup');
        $message->addIndex(['message_id_header'], 'idx_mail_message_message_id');
        $message->addIndex(['address_id'], 'idx_6c00b110f5b7af75');
        $message->addForeignKeyConstraint('mail_inbound_address', ['address_id'], ['id'], ['onDelete' => 'RESTRICT'], 'fk_mail_message_address');
    }

    public function down(Schema $schema): void
    {
        $schema->dropTable('mail_message');
        $schema->dropTable('mail_inbound_address');
    }
}
