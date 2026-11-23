<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\CashFlow\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Types\Types;
use Doctrine\Migrations\AbstractMigration;

/**
 * The VAT of a planned item: how its amount stands to the VAT and the rate.
 * Every item there is states no VAT, so no amount of the forecast changes.
 */
final class Version20261123120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add the VAT mode and rate of a planned item';
    }

    public function up(Schema $schema): void
    {
        $table = $schema->getTable('cash_flow_planned_item');
        $table->addColumn('vat_mode', Types::STRING, ['length' => 8, 'default' => 'none']);
        $table->addColumn('vat_rate', Types::DECIMAL, ['precision' => 5, 'scale' => 2, 'notnull' => false]);
    }

    public function down(Schema $schema): void
    {
        $table = $schema->getTable('cash_flow_planned_item');
        $table->dropColumn('vat_rate');
        $table->dropColumn('vat_mode');
    }
}
