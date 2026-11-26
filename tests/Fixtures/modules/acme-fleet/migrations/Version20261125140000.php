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
 * The fleet's custom fields of a contact: one nullable column per field.
 */
final class Version20261125140000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create the custom fields of acme/fleet on contacts';
    }

    public function up(Schema $schema): void
    {
        $table = $schema->createTable('acme_fleet_contact_extra');
        $table->addColumn('id', Types::GUID);
        $table->addColumn('industry', Types::STRING, ['length' => 20, 'notnull' => false]);
        $table->addColumn('contract_until', Types::DATE_IMMUTABLE, ['notnull' => false]);
        $table->addColumn('account_code', Types::STRING, ['length' => 30, 'notnull' => false]);
        $table->addColumn('services', Types::JSON, ['notnull' => false]);
        $table->setPrimaryKey(['id']);
    }

    public function down(Schema $schema): void
    {
        $schema->dropTable('acme_fleet_contact_extra');
    }
}
