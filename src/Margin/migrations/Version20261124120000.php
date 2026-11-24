<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Margin\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Types\Types;
use Doctrine\Migrations\AbstractMigration;

/**
 * The mappings of Cash flow planned items and the defaults of suppliers,
 * each versioned by the month it applies from. No foreign keys: the planned
 * items belong to an optional module, and a contact may go.
 */
final class Version20261124120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create the margin planned allocations and supplier defaults';
    }

    public function up(Schema $schema): void
    {
        $planned = $schema->createTable('margin_planned_allocation');
        $planned->addColumn('id', Types::GUID);
        $planned->addColumn('planned_item_id', Types::GUID);
        $planned->addColumn('from_month', Types::DATE_IMMUTABLE);
        $planned->addColumn('allocation', Types::JSON, ['notnull' => false]);
        $planned->addColumn('created_at', Types::DATETIME_IMMUTABLE);
        $planned->addColumn('created_by', Types::STRING, ['length' => 64]);
        $planned->setPrimaryKey(['id']);
        $planned->addUniqueIndex(['planned_item_id', 'from_month'], 'uniq_margin_planned_allocation');

        $supplier = $schema->createTable('margin_supplier_default');
        $supplier->addColumn('id', Types::GUID);
        $supplier->addColumn('supplier_id', Types::GUID);
        $supplier->addColumn('from_month', Types::DATE_IMMUTABLE);
        $supplier->addColumn('recipient', Types::STRING, ['length' => 36, 'notnull' => false]);
        $supplier->setPrimaryKey(['id']);
        $supplier->addUniqueIndex(['supplier_id', 'from_month'], 'uniq_margin_supplier_default');
    }

    public function down(Schema $schema): void
    {
        $schema->dropTable('margin_supplier_default');
        $schema->dropTable('margin_planned_allocation');
    }
}
