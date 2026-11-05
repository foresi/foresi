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
 * Deducting advances on a final invoice: a line names the tax document or the
 * advance invoice it deducts; the deducted document names the one invoice that
 * deducts it, so it is deducted once; and the final invoice says it deducts, so a
 * payable of zero or less reads as settled. New columns, nothing to backfill.
 */
final class Version20261105080001 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Deduct advances on a final invoice';
    }

    public function up(Schema $schema): void
    {
        $line = $schema->getTable('invoice_line');
        $line->addColumn('deducts_invoice_id', Types::GUID, ['notnull' => false]);
        $line->addIndex(['deducts_invoice_id'], 'idx_invoice_line_deducts');
        $invoice = $schema->getTable('invoice');
        $invoice->addColumn('deducted_by_invoice_id', Types::GUID, ['notnull' => false]);
        $invoice->addColumn('deducts_advances', Types::BOOLEAN, ['default' => false]);
        $invoice->addIndex(['deducted_by_invoice_id'], 'idx_invoice_deducted_by');
    }

    public function down(Schema $schema): void
    {
        $invoice = $schema->getTable('invoice');
        $invoice->dropIndex('idx_invoice_deducted_by');
        $invoice->dropColumn('deducts_advances');
        $invoice->dropColumn('deducted_by_invoice_id');
        $line = $schema->getTable('invoice_line');
        $line->dropIndex('idx_invoice_line_deducts');
        $line->dropColumn('deducts_invoice_id');
    }
}
