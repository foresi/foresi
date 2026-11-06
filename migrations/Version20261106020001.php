<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * The lists filter tax documents by the month of their tax point, as the issue date
 * already is for the advances.
 */
final class Version20261106020001 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Index the documents of a firm by their tax point';
    }

    public function up(Schema $schema): void
    {
        $schema->getTable('invoice')->addIndex(['tenant_id', 'legal_entity_id', 'tax_point_date'], 'idx_invoice_firm_tax_point');
    }

    public function down(Schema $schema): void
    {
        $schema->getTable('invoice')->dropIndex('idx_invoice_firm_tax_point');
    }
}
