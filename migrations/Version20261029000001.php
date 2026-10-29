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
 * Creates the tenant table. Built with the schema API (not raw SQL) so it runs
 * unchanged on SQLite and PostgreSQL.
 */
final class Version20261029000001 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create the tenant table';
    }

    public function up(Schema $schema): void
    {
        $table = $schema->createTable('tenant');
        $table->addColumn('id', 'tenant_id');
        $table->addColumn('name', Types::STRING, ['length' => 255]);
        $table->addColumn('created_at', Types::DATETIME_IMMUTABLE);
        $table->setPrimaryKey(['id']);
    }

    public function down(Schema $schema): void
    {
        $schema->dropTable('tenant');
    }
}
