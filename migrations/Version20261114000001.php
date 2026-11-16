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
 * The idempotency keys of the API (ADR 0032): one row per organisation, token,
 * operation and key, kept for a day. The primary key is the lock a write takes
 * before it runs. Built with the schema API so it runs unchanged on SQLite and
 * PostgreSQL.
 */
final class Version20261114000001 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add the idempotency keys of the API';
    }

    public function up(Schema $schema): void
    {
        $table = $schema->createTable('api_idempotency_key');
        $table->addColumn('tenant_id', 'tenant_id');
        $table->addColumn('token_id', Types::GUID);
        $table->addColumn('operation', Types::STRING, ['length' => 120]);
        $table->addColumn('idempotency_key', Types::STRING, ['length' => 255]);
        $table->addColumn('fingerprint', Types::STRING, ['length' => 64, 'fixed' => true]);
        $table->addColumn('status', Types::STRING, ['length' => 16]);
        $table->addColumn('response_status', Types::SMALLINT, ['notnull' => false]);
        $table->addColumn('response_headers', Types::TEXT, ['notnull' => false]);
        $table->addColumn('response_body', Types::TEXT, ['notnull' => false]);
        $table->addColumn('created_at', Types::DATETIME_IMMUTABLE);
        $table->addColumn('completed_at', Types::DATETIME_IMMUTABLE, ['notnull' => false]);
        $table->setPrimaryKey(['tenant_id', 'token_id', 'operation', 'idempotency_key']);
        $table->addIndex(['created_at'], 'idx_api_idempotency_key_created');
    }

    public function down(Schema $schema): void
    {
        $schema->dropTable('api_idempotency_key');
    }
}
