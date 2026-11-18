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
 * Saved views of the lists (spec "Quick filters and saved views", D2): the filters,
 * sort and display of a list under a name, private or shared. The views the modules
 * ship are stored by app:views:sync, not here: a migration cannot know which modules
 * an instance has.
 */
final class Version20261118200001 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create the saved views of the lists';
    }

    public function up(Schema $schema): void
    {
        $json = ['platformOptions' => ['jsonb' => true]];
        $views = $schema->createTable('saved_view');
        $views->addColumn('id', Types::GUID);
        $views->addColumn('list_key', Types::STRING, ['length' => 80]);
        $views->addColumn('name', Types::STRING, ['length' => 60]);
        $views->addColumn('owner_id', Types::GUID, ['notnull' => false]);
        $views->addColumn('system_key', Types::STRING, ['length' => 120, 'notnull' => false]);
        $views->addColumn('visibility', Types::STRING, ['length' => 16]);
        $views->addColumn('filters', Types::JSON, $json);
        $views->addColumn('sort', Types::JSON, $json);
        $views->addColumn('display', Types::JSON, ['notnull' => false] + $json);
        $views->addColumn('show_count', Types::BOOLEAN);
        $views->addColumn('placement', Types::STRING, ['length' => 16]);
        $views->addColumn('position', Types::INTEGER);
        $views->addColumn('hidden', Types::BOOLEAN);
        $views->addColumn('created_at', Types::DATETIME_IMMUTABLE);
        $views->addColumn('updated_at', Types::DATETIME_IMMUTABLE);
        $views->addColumn('updated_by', Types::GUID, ['notnull' => false]);
        $views->setPrimaryKey(['id']);
        $views->addIndex(['list_key'], 'idx_saved_view_list');
        $views->addUniqueIndex(['system_key'], 'uniq_saved_view_system_key');
    }

    public function down(Schema $schema): void
    {
        $schema->dropTable('saved_view');
    }
}
