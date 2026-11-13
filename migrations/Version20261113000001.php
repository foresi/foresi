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
 * The webhook endpoints of an organisation. The signing secret and the previous
 * one during a rotation are kept sealed. Built with the schema API so it runs
 * unchanged on SQLite and PostgreSQL.
 */
final class Version20261113000001 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add webhook endpoints';
    }

    public function up(Schema $schema): void
    {
        $endpoint = $schema->createTable('webhook_endpoint');
        $endpoint->addColumn('id', Types::GUID);
        $endpoint->addColumn('tenant_id', 'tenant_id');
        $endpoint->addColumn('url', Types::STRING, ['length' => 2048]);
        $endpoint->addColumn('description', Types::STRING, ['length' => 255, 'notnull' => false]);
        $endpoint->addColumn('event_types', Types::JSON);
        $endpoint->addColumn('version', Types::SMALLINT);
        $endpoint->addColumn('secret', Types::TEXT);
        $endpoint->addColumn('previous_secret', Types::TEXT, ['notnull' => false]);
        $endpoint->addColumn('previous_expires_at', Types::DATETIME_IMMUTABLE, ['notnull' => false]);
        $endpoint->addColumn('active', Types::BOOLEAN);
        $endpoint->addColumn('disabled_reason', Types::STRING, ['length' => 32, 'notnull' => false]);
        $endpoint->addColumn('failing_since', Types::DATETIME_IMMUTABLE, ['notnull' => false]);
        $endpoint->addColumn('owner_id', Types::GUID);
        $endpoint->addColumn('created_at', Types::DATETIME_IMMUTABLE);
        $endpoint->addColumn('updated_at', Types::DATETIME_IMMUTABLE);
        $endpoint->setPrimaryKey(['id']);
        $endpoint->addIndex(['tenant_id', 'active'], 'idx_webhook_endpoint_tenant_active');
    }

    public function down(Schema $schema): void
    {
        $schema->dropTable('webhook_endpoint');
    }
}
