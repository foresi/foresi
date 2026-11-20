<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Shared\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Types\Types;
use Doctrine\Migrations\AbstractMigration;

/**
 * The baseline of Shared: its 3 tables as the global migrations left
 * them (ADR 0034). A database migrated before the module packages moves onto
 * it with app:migrations:rebase, which runs none of this.
 */
final class Version20261120000000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create the tables of Shared';
    }

    public function up(Schema $schema): void
    {
        $this->messengerMessages($schema);
        $this->savedView($schema);
        $this->savedViewUserState($schema);
    }

    public function down(Schema $schema): void
    {
        $schema->dropTable('saved_view_user_state');
        $schema->dropTable('saved_view');
        $schema->dropTable('messenger_messages');
    }

    private function messengerMessages(Schema $schema): void
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
        $table->addIndex(['queue_name', 'available_at', 'delivered_at', 'id'], 'idx_75ea56e0fb7336f0e3bd61ce16ba31dbbf396750');
    }

    private function savedView(Schema $schema): void
    {
        $table = $schema->createTable('saved_view');
        $table->addColumn('id', Types::GUID);
        $table->addColumn('list_key', Types::STRING, ['length' => 80]);
        $table->addColumn('name', Types::STRING, ['length' => 60]);
        $table->addColumn('owner_id', Types::GUID, ['notnull' => false]);
        $table->addColumn('system_key', Types::STRING, ['length' => 120, 'notnull' => false]);
        $table->addColumn('visibility', Types::STRING, ['length' => 16]);
        $table->addColumn('filters', Types::JSON, ['platformOptions' => ['jsonb' => true]]);
        $table->addColumn('sort', Types::JSON, ['platformOptions' => ['jsonb' => true]]);
        $table->addColumn('display', Types::JSON, ['notnull' => false, 'platformOptions' => ['jsonb' => true]]);
        $table->addColumn('show_count', Types::BOOLEAN);
        $table->addColumn('placement', Types::STRING, ['length' => 16]);
        $table->addColumn('position', Types::INTEGER);
        $table->addColumn('hidden', Types::BOOLEAN);
        $table->addColumn('created_at', Types::DATETIME_IMMUTABLE);
        $table->addColumn('updated_at', Types::DATETIME_IMMUTABLE);
        $table->addColumn('updated_by', Types::GUID, ['notnull' => false]);
        $table->setPrimaryKey(['id']);
        $table->addIndex(['list_key'], 'idx_saved_view_list');
        $table->addUniqueIndex(['system_key'], 'uniq_saved_view_system_key');
    }

    private function savedViewUserState(Schema $schema): void
    {
        $table = $schema->createTable('saved_view_user_state');
        $table->addColumn('view_id', Types::GUID);
        $table->addColumn('user_id', Types::GUID);
        $table->addColumn('display', Types::JSON, ['platformOptions' => ['jsonb' => true]]);
        $table->addColumn('updated_at', Types::DATETIME_IMMUTABLE);
        $table->setPrimaryKey(['view_id', 'user_id']);
    }
}
