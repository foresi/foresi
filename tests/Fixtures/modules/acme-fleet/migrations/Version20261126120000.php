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
 * The fleet manager of a contact, a custom field: a nullable column, so the table is
 * not rewritten.
 */
final class Version20261126120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add the fleet manager to the custom fields of acme/fleet on contacts';
    }

    public function up(Schema $schema): void
    {
        $schema->getTable('acme_fleet_contact_extra')->addColumn('fleet_manager', Types::GUID, ['notnull' => false]);
    }

    public function down(Schema $schema): void
    {
        $schema->getTable('acme_fleet_contact_extra')->dropColumn('fleet_manager');
    }
}
