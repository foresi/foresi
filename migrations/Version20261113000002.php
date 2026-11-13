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
 * The webhook deliveries and their attempts. A delivery is stored by the event
 * relay, in its transaction, once per matching endpoint, with the body it is
 * signed and sent with; the unique endpoint and event keep it to one. Built
 * with the schema API so it runs unchanged on SQLite and PostgreSQL.
 */
final class Version20261113000002 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add webhook deliveries and their attempts';
    }

    public function up(Schema $schema): void
    {
        $delivery = $schema->createTable('webhook_delivery');
        $delivery->addColumn('id', Types::GUID);
        $delivery->addColumn('tenant_id', 'tenant_id');
        $delivery->addColumn('endpoint_id', Types::GUID);
        $delivery->addColumn('event_id', Types::GUID);
        $delivery->addColumn('event_type', Types::STRING, ['length' => 128]);
        $delivery->addColumn('body', Types::TEXT);
        $delivery->addColumn('status', Types::STRING, ['length' => 16]);
        $delivery->addColumn('attempts', Types::SMALLINT);
        $delivery->addColumn('next_attempt_at', Types::DATETIME_IMMUTABLE, ['notnull' => false]);
        $delivery->addColumn('last_status_code', Types::SMALLINT, ['notnull' => false]);
        $delivery->addColumn('kind', Types::STRING, ['length' => 16]);
        $delivery->addColumn('created_at', Types::DATETIME_IMMUTABLE);
        $delivery->addColumn('finished_at', Types::DATETIME_IMMUTABLE, ['notnull' => false]);
        $delivery->setPrimaryKey(['id']);
        $delivery->addUniqueIndex(['endpoint_id', 'event_id'], 'uniq_webhook_delivery_endpoint_event');
        $delivery->addIndex(['status', 'next_attempt_at'], 'idx_webhook_delivery_due');
        $delivery->addIndex(['endpoint_id', 'id'], 'idx_webhook_delivery_endpoint');

        $attempt = $schema->createTable('webhook_delivery_attempt');
        $attempt->addColumn('id', Types::GUID);
        $attempt->addColumn('tenant_id', 'tenant_id');
        $attempt->addColumn('delivery_id', Types::GUID);
        $attempt->addColumn('attempted_at', Types::DATETIME_IMMUTABLE);
        $attempt->addColumn('duration_ms', Types::INTEGER);
        $attempt->addColumn('status_code', Types::SMALLINT, ['notnull' => false]);
        $attempt->addColumn('error', Types::STRING, ['length' => 32, 'notnull' => false]);
        $attempt->addColumn('trigger', Types::STRING, ['length' => 16]);
        $attempt->setPrimaryKey(['id']);
        $attempt->addIndex(['delivery_id', 'attempted_at'], 'idx_webhook_attempt_delivery');
    }

    public function down(Schema $schema): void
    {
        $schema->dropTable('webhook_delivery_attempt');
        $schema->dropTable('webhook_delivery');
    }
}
