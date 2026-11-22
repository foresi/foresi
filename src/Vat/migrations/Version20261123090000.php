<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Vat\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Types\Types;
use Doctrine\Migrations\AbstractMigration;

/**
 * What people decide about the VAT periods: the return amount and the
 * "settled" mark of a period, and the assignment of a bank movement to a
 * period, or to none.
 */
final class Version20261123090000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create the VAT period entries and payment assignments';
    }

    public function up(Schema $schema): void
    {
        $entry = $schema->createTable('vat_period_entry');
        $entry->addColumn('period_key', Types::STRING, ['length' => 7]);
        $entry->addColumn('return_amount_minor', Types::BIGINT, ['notnull' => false]);
        $entry->addColumn('settled_at', Types::DATETIME_IMMUTABLE, ['notnull' => false]);
        $entry->addColumn('settled_by', Types::STRING, ['length' => 64, 'notnull' => false]);
        $entry->addColumn('settled_note', Types::STRING, ['length' => 500, 'notnull' => false]);
        $entry->setPrimaryKey(['period_key']);

        $assignment = $schema->createTable('vat_payment_assignment');
        $assignment->addColumn('movement_id', Types::STRING, ['length' => 64]);
        $assignment->addColumn('period_key', Types::STRING, ['length' => 7, 'notnull' => false]);
        $assignment->addColumn('assigned_at', Types::DATETIME_IMMUTABLE);
        $assignment->addColumn('assigned_by', Types::STRING, ['length' => 64]);
        $assignment->setPrimaryKey(['movement_id']);
    }

    public function down(Schema $schema): void
    {
        $schema->dropTable('vat_payment_assignment');
        $schema->dropTable('vat_period_entry');
    }
}
