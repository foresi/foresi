<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Catalog\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Types\Types;
use Doctrine\Migrations\AbstractMigration;

/**
 * The products of the catalog (plan 32e). The billing kind is a plain string,
 * so a new kind needs no data migration.
 */
final class Version20261122130000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create the products of the catalog';
    }

    public function up(Schema $schema): void
    {
        $table = $schema->createTable('catalog_product');
        $table->addColumn('id', Types::GUID);
        $table->addColumn('name', Types::STRING, ['length' => 200]);
        $table->addColumn('description', Types::TEXT);
        $table->addColumn('unit', Types::STRING, ['length' => 32]);
        $table->addColumn('unit_price_minor', Types::BIGINT, ['notnull' => false]);
        $table->addColumn('currency', Types::STRING, ['length' => 3, 'notnull' => false]);
        $table->addColumn('billing_kind', Types::STRING, ['length' => 16]);
        $table->addColumn('recurrence_unit', Types::STRING, ['length' => 8, 'notnull' => false]);
        $table->addColumn('recurrence_interval', Types::SMALLINT, ['notnull' => false]);
        $table->addColumn('cycles', Types::SMALLINT, ['notnull' => false]);
        $table->addColumn('archived_at', Types::DATETIME_IMMUTABLE, ['notnull' => false]);
        $table->addColumn('created_at', Types::DATETIME_IMMUTABLE);
        $table->setPrimaryKey(['id']);
    }

    public function down(Schema $schema): void
    {
        $schema->dropTable('catalog_product');
    }
}
