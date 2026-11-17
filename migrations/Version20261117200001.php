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
 * The payments of received documents (decision C3 of the cash flow spec): the
 * day, the amount in the document's currency, the method and an optional bank
 * movement; what is paid and what remains are derived. Built with the schema
 * API so it runs unchanged on SQLite and PostgreSQL.
 */
final class Version20261117200001 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add the payments of received documents';
    }

    public function up(Schema $schema): void
    {
        $table = $schema->createTable('received_document_payment');
        $table->addColumn('id', Types::GUID);
        $table->addColumn('tenant_id', 'tenant_id');
        $table->addColumn('received_document_id', Types::GUID);
        $table->addColumn('paid_on', Types::DATE_IMMUTABLE);
        $table->addColumn('amount_minor', Types::BIGINT);
        $table->addColumn('currency', Types::STRING, ['length' => 3, 'fixed' => true]);
        $table->addColumn('method', Types::STRING, ['length' => 16]);
        $table->addColumn('bank_movement_id', Types::GUID, ['notnull' => false]);
        $table->addColumn('created_by', Types::GUID, ['notnull' => false]);
        $table->addColumn('created_at', Types::DATETIME_IMMUTABLE);
        $table->setPrimaryKey(['id']);
        $table->addIndex(['tenant_id'], 'idx_received_document_payment_tenant');
        $table->addIndex(['received_document_id'], 'idx_received_document_payment_document');
        $table->addIndex(['bank_movement_id'], 'idx_received_document_payment_movement');
        $table->addForeignKeyConstraint('received_document', ['received_document_id'], ['id'], ['onDelete' => 'RESTRICT'], 'fk_received_document_payment_document');
    }

    public function down(Schema $schema): void
    {
        $schema->dropTable('received_document_payment');
    }
}
