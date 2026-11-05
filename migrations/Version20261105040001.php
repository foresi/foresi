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
 * A tax document on payment names the advance invoice and the payment it taxes,
 * once: the pair is unique, and NULLs, which every other document holds, never
 * collide. New columns, so nothing to backfill.
 */
final class Version20261105040001 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Link a tax document on payment to its advance invoice and payment';
    }

    public function up(Schema $schema): void
    {
        $invoice = $schema->getTable('invoice');
        $invoice->addColumn('advance_invoice_id', Types::GUID, ['notnull' => false]);
        $invoice->addColumn('payment_id', Types::GUID, ['notnull' => false]);
        $invoice->addUniqueIndex(['advance_invoice_id', 'payment_id'], 'uniq_invoice_taxed_payment');
        $invoice->addIndex(['payment_id'], 'idx_invoice_payment');
    }

    public function down(Schema $schema): void
    {
        $invoice = $schema->getTable('invoice');
        $invoice->dropIndex('idx_invoice_payment');
        $invoice->dropIndex('uniq_invoice_taxed_payment');
        $invoice->dropColumn('payment_id');
        $invoice->dropColumn('advance_invoice_id');
    }
}
