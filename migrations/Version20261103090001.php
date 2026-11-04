<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Types\Types;
use Doctrine\Migrations\AbstractMigration;

/**
 * The log of inbound bank mail. A new table, so nothing to backfill. The feed is
 * named by its id only, as rows are written by plain SQL before any entity of
 * the tenant is loaded, and feeds are never deleted.
 */
final class Version20261103090001 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add the inbound bank mail log';
    }

    public function up(Schema $schema): void
    {
        $message = $schema->createTable('inbound_message');
        $message->addColumn('id', Types::GUID);
        $message->addColumn('tenant_id', 'tenant_id');
        $message->addColumn('bank_feed_id', Types::GUID);
        $message->addColumn('channel', Types::STRING, ['length' => 16]);
        $message->addColumn('dedup_key', Types::STRING, ['length' => 64, 'fixed' => true]);
        $message->addColumn('raw_key', Types::STRING, ['length' => 128]);
        $message->addColumn('raw_sha256', Types::STRING, ['length' => 64, 'fixed' => true]);
        $message->addColumn('size', Types::INTEGER);
        $message->addColumn('from_address', Types::STRING, ['length' => 320, 'notnull' => false]);
        $message->addColumn('subject', Types::STRING, ['length' => 255]);
        $message->addColumn('received_at', Types::DATETIME_IMMUTABLE);
        $message->addColumn('status', Types::STRING, ['length' => 16]);
        $message->addColumn('trust', Types::STRING, ['length' => 16, 'notnull' => false]);
        $message->addColumn('trust_basis', Types::STRING, ['length' => 32, 'notnull' => false]);
        $message->addColumn('parser_id', Types::STRING, ['length' => 64, 'notnull' => false]);
        $message->addColumn('parser_version', Types::INTEGER, ['notnull' => false]);
        $message->addColumn('failure', Types::STRING, ['length' => 32, 'notnull' => false]);
        $message->addColumn('failure_detail', Types::TEXT, ['notnull' => false]);
        $message->addColumn('attempts', Types::INTEGER);
        $message->addColumn('processed_at', Types::DATETIME_IMMUTABLE, ['notnull' => false]);
        $message->setPrimaryKey(['id']);
        $message->addIndex(['tenant_id'], 'idx_inbound_message_tenant');
        $message->addIndex(['bank_feed_id'], 'idx_inbound_message_feed');
        $message->addIndex(['status'], 'idx_inbound_message_status');
        $message->addUniqueIndex(['tenant_id', 'dedup_key'], 'uniq_inbound_message_dedup');
    }

    public function down(Schema $schema): void
    {
        $schema->dropTable('inbound_message');
    }
}
