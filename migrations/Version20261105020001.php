<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * An advance invoice is no tax document and has no tax point, so the column takes
 * NULL. Every stored document is an invoice and keeps its date. Going back gives
 * an advance its issue date as the tax point first, the day its rates were taken
 * on; the statement runs before the column turns NOT NULL again.
 */
final class Version20261105020001 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Let an advance invoice have no tax point';
    }

    public function up(Schema $schema): void
    {
        $schema->getTable('invoice')->modifyColumn('tax_point_date', ['notnull' => false]);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('UPDATE invoice SET tax_point_date = issue_date WHERE tax_point_date IS NULL');
        $schema->getTable('invoice')->modifyColumn('tax_point_date', ['notnull' => true]);
    }
}
