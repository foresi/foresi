<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\CashFlow\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * The VAT mode of a planned item holds reverse_charge, longer than the eight
 * characters of the modes before it. No item changes its mode.
 */
final class Version20261123150000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Widen the VAT mode of a planned item for reverse_charge';
    }

    public function up(Schema $schema): void
    {
        $schema->getTable('cash_flow_planned_item')->modifyColumn('vat_mode', ['length' => 16]);
    }

    public function down(Schema $schema): void
    {
        $this->abortIf(
            false !== $this->connection->fetchOne("SELECT 1 FROM cash_flow_planned_item WHERE vat_mode = 'reverse_charge' LIMIT 1"),
            'A planned item states a reverse charge, which eight characters do not hold.',
        );
        $schema->getTable('cash_flow_planned_item')->modifyColumn('vat_mode', ['length' => 8]);
    }
}
