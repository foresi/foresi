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
 * The rebill links of recurring invoice lines to planned items and the
 * pairings of invoice lines with the occurrences they rebill. No foreign
 * keys: the lines and the planned items belong to optional modules.
 */
final class Version20261124140000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create the margin rebill links and rebill pairings';
    }

    public function up(Schema $schema): void
    {
        $link = $schema->createTable('margin_rebill_link');
        $link->addColumn('id', Types::GUID);
        $link->addColumn('recurring_line_id', Types::GUID);
        $link->addColumn('planned_item_id', Types::GUID);
        $link->addColumn('created_on', Types::DATE_IMMUTABLE);
        $link->setPrimaryKey(['id']);
        $link->addUniqueIndex(['recurring_line_id'], 'uniq_margin_rebill_link_line');

        $pairing = $schema->createTable('margin_rebill_pairing');
        $pairing->addColumn('id', Types::GUID);
        $pairing->addColumn('invoice_line_id', Types::GUID);
        $pairing->addColumn('invoice_id', Types::GUID);
        $pairing->addColumn('customer_id', Types::GUID);
        $pairing->addColumn('planned_item_id', Types::GUID);
        $pairing->addColumn('occurrence_on', Types::DATE_IMMUTABLE);
        $pairing->addColumn('manual', Types::BOOLEAN);
        $pairing->setPrimaryKey(['id']);
        $pairing->addUniqueIndex(['invoice_line_id'], 'uniq_margin_rebill_pairing_line');
        $pairing->addIndex(['planned_item_id', 'occurrence_on'], 'idx_margin_rebill_pairing_occurrence');
    }

    public function down(Schema $schema): void
    {
        $schema->dropTable('margin_rebill_pairing');
        $schema->dropTable('margin_rebill_link');
    }
}
