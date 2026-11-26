<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Deals\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * The billing plan of a won deal: its planned invoices, each with an amount
 * (a one-off entry) or a recurring line it follows, a day or none, and the
 * invoice or recurring invoice that fulfils it.
 */
final class Version20261126120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add the billing plan of a won deal';
    }

    public function up(Schema $schema): void
    {
        $table = $schema->createTable('sales_billing_plan_entry');
        $table->addColumn('id', 'guid');
        $table->addColumn('deal_id', 'guid');
        $table->addColumn('kind', 'string', ['length' => 16]);
        $table->addColumn('line_id', 'guid', ['notnull' => false]);
        $table->addColumn('amount_minor', 'bigint', ['notnull' => false]);
        $table->addColumn('currency', 'string', ['length' => 3, 'notnull' => false]);
        $table->addColumn('expected_on', 'date_immutable', ['notnull' => false]);
        $table->addColumn('text', 'string', ['length' => 200]);
        $table->addColumn('fulfilled_type', 'string', ['length' => 32, 'notnull' => false]);
        $table->addColumn('fulfilled_id', 'guid', ['notnull' => false]);
        $table->addColumn('created_at', 'datetime_immutable');
        $table->addColumn('updated_at', 'datetime_immutable');
        $table->setPrimaryKey(['id']);
        $table->addIndex(['deal_id'], 'idx_sales_billing_plan_entry_deal');
        $table->addIndex(['fulfilled_type', 'fulfilled_id'], 'idx_sales_billing_plan_entry_fulfilled');
        $table->addUniqueIndex(['line_id'], 'uniq_sales_billing_plan_entry_line');
    }

    public function down(Schema $schema): void
    {
        $schema->dropTable('sales_billing_plan_entry');
    }
}
