<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\TaxesAndContributions\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Types\Types;
use Doctrine\Migrations\AbstractMigration;

/**
 * The baseline of Taxes and contributions: its 2 tables as the global migrations left
 * them (ADR 0034). A database migrated before the module packages moves onto
 * it with app:migrations:rebase, which runs none of this.
 */
final class Version20261120000000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create the tables of Taxes and contributions';
    }

    public function up(Schema $schema): void
    {
        $this->taxesOwnerProfile($schema);
        $this->taxesStatusPeriod($schema);
    }

    public function down(Schema $schema): void
    {
        $schema->dropTable('taxes_status_period');
        $schema->dropTable('taxes_owner_profile');
    }

    private function taxesOwnerProfile(Schema $schema): void
    {
        $table = $schema->createTable('taxes_owner_profile');
        $table->addColumn('id', Types::SMALLINT);
        $table->addColumn('years', Types::JSON);
        $table->addColumn('authorities', Types::JSON);
        $table->setPrimaryKey(['id']);
    }

    private function taxesStatusPeriod(Schema $schema): void
    {
        $table = $schema->createTable('taxes_status_period');
        $table->addColumn('id', Types::GUID);
        $table->addColumn('profile_id', Types::SMALLINT);
        $table->addColumn('kind', Types::STRING, ['length' => 40]);
        $table->addColumn('value', Types::STRING, ['length' => 20]);
        $table->addColumn('valid_from', Types::DATE_IMMUTABLE);
        $table->addColumn('base_minor', Types::BIGINT, ['notnull' => false]);
        $table->setPrimaryKey(['id']);
        $table->addIndex(['profile_id'], 'idx_93b3f5c9ccfa12b8');
        $table->addUniqueIndex(['kind', 'valid_from'], 'uniq_taxes_status_period_kind_day');
        $table->addForeignKeyConstraint('taxes_owner_profile', ['profile_id'], ['id'], ['onDelete' => 'CASCADE'], 'fk_93b3f5c9ccfa12b8');
    }
}
