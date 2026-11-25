<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Acme\Fleet\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Types\Types;
use Doctrine\Migrations\AbstractMigration;

/**
 * The vehicles of the firm.
 */
final class Version20261125120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create the vehicles of acme/fleet';
    }

    public function up(Schema $schema): void
    {
        $table = $schema->createTable('acme_fleet_vehicle');
        $table->addColumn('id', Types::GUID);
        $table->addColumn('name', Types::STRING, ['length' => 100]);
        $table->addColumn('plate', Types::STRING, ['length' => 16]);
        $table->addColumn('driver_contact_id', Types::GUID, ['notnull' => false]);
        $table->addColumn('created_at', Types::DATETIME_IMMUTABLE);
        $table->setPrimaryKey(['id']);
        $table->addUniqueIndex(['plate'], 'acme_fleet_vehicle_plate');
        $table->addIndex(['driver_contact_id'], 'acme_fleet_vehicle_driver');
    }

    public function down(Schema $schema): void
    {
        $schema->dropTable('acme_fleet_vehicle');
    }
}
