<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Types\Types;
use Doctrine\Migrations\AbstractMigration;

/**
 * Corrective tax documents: a correction names the document it corrects, the
 * reason, whether a change or an error, and the day of the fact. New nullable
 * columns, nothing to backfill.
 */
final class Version20261105090001 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Link a corrective tax document to the document it corrects';
    }

    public function up(Schema $schema): void
    {
        $invoice = $schema->getTable('invoice');
        $invoice->addColumn('corrected_invoice_id', Types::GUID, ['notnull' => false]);
        $invoice->addColumn('correction_reason', Types::TEXT, ['notnull' => false]);
        $invoice->addColumn('correction_type', Types::STRING, ['length' => 8, 'notnull' => false]);
        $invoice->addColumn('decisive_date', Types::DATE_IMMUTABLE, ['notnull' => false]);
        $invoice->addIndex(['corrected_invoice_id'], 'idx_invoice_corrected');
    }

    public function down(Schema $schema): void
    {
        $invoice = $schema->getTable('invoice');
        $invoice->dropIndex('idx_invoice_corrected');
        $invoice->dropColumn('decisive_date');
        $invoice->dropColumn('correction_type');
        $invoice->dropColumn('correction_reason');
        $invoice->dropColumn('corrected_invoice_id');
    }
}
