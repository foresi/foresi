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
 * Creates the table of the Doctrine Messenger transport (queues async,
 * registry and failed). Built with the schema API so it runs unchanged on
 * SQLite and PostgreSQL; the layout is the one the transport expects.
 */
final class Version20261030000001 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create the messenger_messages table of the background queue';
    }

    public function up(Schema $schema): void
    {
        $table = $schema->createTable('messenger_messages');
        $table->addColumn('id', Types::BIGINT, ['autoincrement' => true]);
        $table->addColumn('body', Types::TEXT);
        $table->addColumn('headers', Types::TEXT);
        $table->addColumn('queue_name', Types::STRING, ['length' => 190]);
        $table->addColumn('created_at', Types::DATETIME_IMMUTABLE);
        $table->addColumn('available_at', Types::DATETIME_IMMUTABLE);
        $table->addColumn('delivered_at', Types::DATETIME_IMMUTABLE, ['notnull' => false]);
        $table->setPrimaryKey(['id']);
        $table->addIndex(['queue_name', 'available_at', 'delivered_at', 'id'], 'IDX_75EA56E0FB7336F0E3BD61CE16BA31DBBF396750');
    }

    public function down(Schema $schema): void
    {
        $schema->dropTable('messenger_messages');
    }
}
