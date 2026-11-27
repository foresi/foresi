<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Deals\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * The quantity of a deal line may be empty: a time-based line has hours that
 * are expected, not yet estimated.
 */
final class Version20261124220000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Let the quantity of a deal line be empty';
    }

    public function up(Schema $schema): void
    {
        $schema->getTable('sales_deal_line')->modifyColumn('quantity', ['notnull' => false]);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('UPDATE sales_deal_line SET quantity = 1 WHERE quantity IS NULL');
        $schema->getTable('sales_deal_line')->modifyColumn('quantity', ['notnull' => true]);
    }
}
