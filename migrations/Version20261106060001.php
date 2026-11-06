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
 * Where an imported document came from, the run that brought it and the PDF the
 * source drew. Nothing stored so far was imported, so every column starts empty,
 * and empty columns repeat freely under the unique index.
 */
final class Version20261106060001 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Keep the source, the run and the original PDF of an imported document';
    }

    public function up(Schema $schema): void
    {
        $invoice = $schema->getTable('invoice');
        $invoice->addColumn('import_source', Types::STRING, ['length' => 32, 'notnull' => false]);
        $invoice->addColumn('import_external_id', Types::STRING, ['length' => 64, 'notnull' => false]);
        $invoice->addColumn('import_run_id', Types::GUID, ['notnull' => false]);
        $invoice->addColumn('imported_at', Types::DATETIME_IMMUTABLE, ['notnull' => false]);
        $invoice->addColumn('original_pdf_key', Types::STRING, ['length' => 255, 'notnull' => false]);
        $invoice->addColumn('original_pdf_sha256', Types::STRING, ['length' => 64, 'fixed' => true, 'notnull' => false]);
        $invoice->addUniqueIndex(['legal_entity_id', 'import_source', 'import_external_id'], 'uniq_invoice_import');
        $invoice->addIndex(['import_run_id'], 'idx_invoice_import_run');
    }

    public function down(Schema $schema): void
    {
        $invoice = $schema->getTable('invoice');
        $invoice->dropIndex('idx_invoice_import_run');
        $invoice->dropIndex('uniq_invoice_import');
        $invoice->dropColumn('original_pdf_sha256');
        $invoice->dropColumn('original_pdf_key');
        $invoice->dropColumn('imported_at');
        $invoice->dropColumn('import_run_id');
        $invoice->dropColumn('import_external_id');
        $invoice->dropColumn('import_source');
    }
}
