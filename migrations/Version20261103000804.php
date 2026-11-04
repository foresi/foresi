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
 * The recurring template and the occurrence an invoice was issued for, unique in a
 * tenant, so an occurrence is issued once. Existing invoices came from no template
 * and hold null in both, which repeats freely on SQLite and PostgreSQL alike.
 */
final class Version20261103000804 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add the recurring template and occurrence of an invoice, unique per tenant';
    }

    public function up(Schema $schema): void
    {
        $invoice = $schema->getTable('invoice');
        $invoice->addColumn('recurring_invoice_id', Types::GUID, ['notnull' => false]);
        $invoice->addColumn('recurring_occurrence', Types::DATE_IMMUTABLE, ['notnull' => false]);
        $invoice->addUniqueIndex(['tenant_id', 'recurring_invoice_id', 'recurring_occurrence'], 'uniq_invoice_occurrence');
    }

    public function down(Schema $schema): void
    {
        $invoice = $schema->getTable('invoice');
        $invoice->dropIndex('uniq_invoice_occurrence');
        $invoice->dropColumn('recurring_occurrence');
        $invoice->dropColumn('recurring_invoice_id');
    }
}
