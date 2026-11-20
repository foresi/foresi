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
 * The owner profile of the taxes and contributions (B7): one row per instance,
 * kept so by its constant key, holding the inputs of each year and the
 * authority accounts; and its dated statuses, of which two of one kind never
 * start on one day. Both tables are new, so going down drops them.
 */
final class Version20261120200001 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create the owner profile and its status periods of the taxes and contributions';
    }

    public function up(Schema $schema): void
    {
        $profile = $schema->createTable('taxes_owner_profile');
        $profile->addColumn('id', Types::SMALLINT);
        $profile->addColumn('years', Types::JSON);
        $profile->addColumn('authorities', Types::JSON);
        $profile->setPrimaryKey(['id']);

        $status = $schema->createTable('taxes_status_period');
        $status->addColumn('id', Types::GUID);
        $status->addColumn('profile_id', Types::SMALLINT);
        $status->addColumn('kind', Types::STRING, ['length' => 40]);
        $status->addColumn('value', Types::STRING, ['length' => 20]);
        $status->addColumn('valid_from', Types::DATE_IMMUTABLE);
        $status->addColumn('base_minor', Types::BIGINT, ['notnull' => false]);
        $status->setPrimaryKey(['id']);
        $status->addUniqueIndex(['kind', 'valid_from'], 'uniq_taxes_status_period_kind_day');
        $status->addIndex(['profile_id'], 'IDX_93B3F5C9CCFA12B8');
        $status->addForeignKeyConstraint('taxes_owner_profile', ['profile_id'], ['id'], ['onDelete' => 'CASCADE'], 'FK_93B3F5C9CCFA12B8');
    }

    public function down(Schema $schema): void
    {
        $schema->dropTable('taxes_status_period');
        $schema->dropTable('taxes_owner_profile');
    }
}
