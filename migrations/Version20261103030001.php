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
 * Payments and what each of them pays. New tables, so nothing to backfill: every
 * invoice so far is unpaid, which is what no allocation means.
 */
final class Version20261103030001 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add payments and their allocations to invoices';
    }

    public function up(Schema $schema): void
    {
        $payment = $schema->createTable('payment');
        $payment->addColumn('id', Types::GUID);
        $payment->addColumn('tenant_id', 'tenant_id');
        $payment->addColumn('legal_entity_id', Types::GUID);
        $payment->addColumn('received_on', Types::DATE_IMMUTABLE);
        $payment->addColumn('amount_minor', Types::BIGINT);
        $payment->addColumn('currency', Types::STRING, ['length' => 3, 'fixed' => true]);
        $payment->addColumn('method', Types::STRING, ['length' => 16]);
        $payment->addColumn('source', Types::STRING, ['length' => 16]);
        $payment->addColumn('bank_movement_id', Types::GUID, ['notnull' => false]);
        $payment->addColumn('note', Types::STRING, ['length' => 500]);
        $payment->addColumn('created_by', Types::GUID, ['notnull' => false]);
        $payment->addColumn('created_at', Types::DATETIME_IMMUTABLE);
        $payment->addColumn('cancelled_at', Types::DATETIME_IMMUTABLE, ['notnull' => false]);
        $payment->addColumn('cancelled_by', Types::GUID, ['notnull' => false]);
        $payment->setPrimaryKey(['id']);
        $payment->addIndex(['tenant_id'], 'idx_payment_tenant');
        $payment->addIndex(['tenant_id', 'legal_entity_id', 'received_on'], 'idx_payment_firm_received');
        $payment->addIndex(['bank_movement_id'], 'idx_payment_bank_movement');
        $payment->addIndex(['legal_entity_id'], 'idx_payment_firm');
        $payment->addForeignKeyConstraint('legal_entity', ['legal_entity_id'], ['id'], ['onDelete' => 'RESTRICT'], 'fk_payment_firm');

        $allocation = $schema->createTable('payment_allocation');
        $allocation->addColumn('id', Types::GUID);
        $allocation->addColumn('tenant_id', 'tenant_id');
        $allocation->addColumn('payment_id', Types::GUID);
        $allocation->addColumn('invoice_id', Types::GUID);
        $allocation->addColumn('amount_minor', Types::BIGINT);
        $allocation->addColumn('settlement_minor', Types::BIGINT, ['default' => 0]);
        $allocation->setPrimaryKey(['id']);
        $allocation->addIndex(['tenant_id'], 'idx_payment_allocation_tenant');
        $allocation->addIndex(['invoice_id'], 'idx_payment_allocation_invoice');
        $allocation->addIndex(['payment_id'], 'idx_payment_allocation_payment');
        $allocation->addForeignKeyConstraint('payment', ['payment_id'], ['id'], ['onDelete' => 'RESTRICT'], 'fk_payment_allocation_payment');
        $allocation->addForeignKeyConstraint('invoice', ['invoice_id'], ['id'], ['onDelete' => 'RESTRICT'], 'fk_payment_allocation_invoice');
    }

    public function down(Schema $schema): void
    {
        $schema->dropTable('payment_allocation');
        $schema->dropTable('payment');
    }
}
