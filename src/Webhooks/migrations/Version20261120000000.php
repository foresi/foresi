<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Webhooks\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Types\Types;
use Doctrine\Migrations\AbstractMigration;

/**
 * The baseline of Webhooks: its 3 tables as the global migrations left
 * them (ADR 0034). A database migrated before the module packages moves onto
 * it with app:migrations:rebase, which runs none of this.
 */
final class Version20261120000000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create the tables of Webhooks';
    }

    public function up(Schema $schema): void
    {
        $this->webhookDelivery($schema);
        $this->webhookDeliveryAttempt($schema);
        $this->webhookEndpoint($schema);
    }

    public function down(Schema $schema): void
    {
        $schema->dropTable('webhook_endpoint');
        $schema->dropTable('webhook_delivery_attempt');
        $schema->dropTable('webhook_delivery');
    }

    private function webhookDelivery(Schema $schema): void
    {
        $table = $schema->createTable('webhook_delivery');
        $table->addColumn('id', Types::GUID);
        $table->addColumn('endpoint_id', Types::GUID);
        $table->addColumn('event_id', Types::GUID);
        $table->addColumn('event_type', Types::STRING, ['length' => 128]);
        $table->addColumn('body', Types::TEXT);
        $table->addColumn('status', Types::STRING, ['length' => 16]);
        $table->addColumn('attempts', Types::SMALLINT);
        $table->addColumn('next_attempt_at', Types::DATETIME_IMMUTABLE, ['notnull' => false]);
        $table->addColumn('last_status_code', Types::SMALLINT, ['notnull' => false]);
        $table->addColumn('kind', Types::STRING, ['length' => 16]);
        $table->addColumn('created_at', Types::DATETIME_IMMUTABLE);
        $table->addColumn('finished_at', Types::DATETIME_IMMUTABLE, ['notnull' => false]);
        $table->setPrimaryKey(['id']);
        $table->addIndex(['status', 'next_attempt_at'], 'idx_webhook_delivery_due');
        $table->addIndex(['endpoint_id', 'id'], 'idx_webhook_delivery_endpoint');
        $table->addUniqueIndex(['endpoint_id', 'event_id'], 'uniq_webhook_delivery_endpoint_event');
    }

    private function webhookDeliveryAttempt(Schema $schema): void
    {
        $table = $schema->createTable('webhook_delivery_attempt');
        $table->addColumn('id', Types::GUID);
        $table->addColumn('delivery_id', Types::GUID);
        $table->addColumn('attempted_at', Types::DATETIME_IMMUTABLE);
        $table->addColumn('duration_ms', Types::INTEGER);
        $table->addColumn('status_code', Types::SMALLINT, ['notnull' => false]);
        $table->addColumn('error', Types::STRING, ['length' => 32, 'notnull' => false]);
        $table->addColumn('trigger', Types::STRING, ['length' => 16]);
        $table->setPrimaryKey(['id']);
        $table->addIndex(['delivery_id', 'attempted_at'], 'idx_webhook_attempt_delivery');
    }

    private function webhookEndpoint(Schema $schema): void
    {
        $table = $schema->createTable('webhook_endpoint');
        $table->addColumn('id', Types::GUID);
        $table->addColumn('url', Types::STRING, ['length' => 2048]);
        $table->addColumn('description', Types::STRING, ['length' => 255, 'notnull' => false]);
        $table->addColumn('event_types', Types::JSON);
        $table->addColumn('version', Types::SMALLINT);
        $table->addColumn('secret', Types::TEXT);
        $table->addColumn('previous_secret', Types::TEXT, ['notnull' => false]);
        $table->addColumn('previous_expires_at', Types::DATETIME_IMMUTABLE, ['notnull' => false]);
        $table->addColumn('active', Types::BOOLEAN);
        $table->addColumn('disabled_reason', Types::STRING, ['length' => 32, 'notnull' => false]);
        $table->addColumn('failing_since', Types::DATETIME_IMMUTABLE, ['notnull' => false]);
        $table->addColumn('owner_id', Types::GUID);
        $table->addColumn('created_at', Types::DATETIME_IMMUTABLE);
        $table->addColumn('updated_at', Types::DATETIME_IMMUTABLE);
        $table->setPrimaryKey(['id']);
        $table->addIndex(['active'], 'idx_webhook_endpoint_active');
    }
}
