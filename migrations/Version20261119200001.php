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
 * Labels go to the trash: when and by whom, and the name held unique only while
 * a label is out of the trash, in live_name (null in the trash), as a partial
 * index cannot be read back from SQLite (see Version20261101000003). Every
 * existing label is out of the trash, so live_name is its name; that statement
 * needs the column, so it runs in postUp().
 */
final class Version20261119200001 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Labels go to the trash: deleted_at, deleted_by and a name unique only out of the trash';
    }

    public function up(Schema $schema): void
    {
        $label = $schema->getTable('label');
        $label->dropIndex('uniq_label_name');
        $label->addColumn('live_name', Types::STRING, ['length' => 60, 'notnull' => false]);
        $label->addColumn('deleted_at', Types::DATETIME_IMMUTABLE, ['notnull' => false]);
        $label->addColumn('deleted_by', Types::GUID, ['notnull' => false]);
        $label->addUniqueIndex(['live_name'], 'uniq_label_live_name');
        $label->addIndex(['deleted_at'], 'idx_label_deleted');
    }

    public function postUp(Schema $schema): void
    {
        // A dry run changes no schema, so the column is not there to fill.
        if (!\array_key_exists('live_name', $this->connection->createSchemaManager()->listTableColumns('label'))) {
            return;
        }

        $this->connection->executeStatement('UPDATE label SET live_name = name WHERE deleted_at IS NULL');
    }

    public function preDown(Schema $schema): void
    {
        $this->abortIf(
            false !== $this->connection->fetchOne('SELECT 1 FROM label WHERE deleted_at IS NOT NULL'),
            'Labels are in the trash; restore or purge them before going down.',
        );
    }

    public function down(Schema $schema): void
    {
        $label = $schema->getTable('label');
        $label->dropIndex('idx_label_deleted');
        $label->dropIndex('uniq_label_live_name');
        $label->dropColumn('deleted_by');
        $label->dropColumn('deleted_at');
        $label->dropColumn('live_name');
        $label->addUniqueIndex(['name'], 'uniq_label_name');
    }
}
