<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Types\Types;
use Doctrine\Migrations\AbstractMigration;

final class Version20261118610001 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Put deleted time in the trash instead of removing it';
    }

    public function up(Schema $schema): void
    {
        $time = $schema->getTable('time_entry');
        $time->addColumn('deleted_at', Types::DATETIME_IMMUTABLE, ['notnull' => false]);
        $time->addColumn('deleted_by', Types::GUID, ['notnull' => false]);
        $time->addIndex(['project_id', 'deleted_at'], 'idx_time_entry_deleted');
    }

    public function down(Schema $schema): void
    {
        $time = $schema->getTable('time_entry');
        $time->dropIndex('idx_time_entry_deleted');
        $time->dropColumn('deleted_by');
        $time->dropColumn('deleted_at');
    }
}
