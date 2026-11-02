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
 * The sends of documents by e-mail. A new table, so nothing to backfill.
 */
final class Version20261102000006 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add the sends of invoices by e-mail';
    }

    public function up(Schema $schema): void
    {
        $send = $schema->createTable('invoice_send');
        $send->addColumn('id', Types::GUID);
        $send->addColumn('tenant_id', 'tenant_id');
        $send->addColumn('invoice_id', Types::GUID);
        $send->addColumn('status', Types::STRING, ['length' => 16]);
        $send->addColumn('recipients_to', Types::JSON);
        $send->addColumn('recipients_cc', Types::JSON);
        $send->addColumn('copy_to', Types::STRING, ['length' => 254, 'notnull' => false]);
        $send->addColumn('subject', Types::STRING, ['length' => 255]);
        $send->addColumn('body', Types::TEXT);
        $send->addColumn('language', Types::STRING, ['length' => 2, 'fixed' => true]);
        $send->addColumn('invoice_version', Types::INTEGER);
        $send->addColumn('created_by', Types::GUID, ['notnull' => false]);
        $send->addColumn('created_at', Types::DATETIME_IMMUTABLE);
        $send->addColumn('sent_at', Types::DATETIME_IMMUTABLE, ['notnull' => false]);
        $send->addColumn('failed_at', Types::DATETIME_IMMUTABLE, ['notnull' => false]);
        $send->addColumn('cancelled_at', Types::DATETIME_IMMUTABLE, ['notnull' => false]);
        $send->addColumn('attempts', Types::INTEGER);
        $send->addColumn('last_attempt_at', Types::DATETIME_IMMUTABLE, ['notnull' => false]);
        $send->addColumn('last_error', Types::STRING, ['length' => 500, 'notnull' => false]);
        $send->addColumn('settings_problem', Types::BOOLEAN);
        $send->addColumn('message_id', Types::STRING, ['length' => 255, 'notnull' => false]);
        $send->addColumn('claimed_at', Types::DATETIME_IMMUTABLE, ['notnull' => false]);
        $send->addColumn('token_hash', Types::STRING, ['length' => 64, 'fixed' => true]);
        $send->addColumn('copy_token_hash', Types::STRING, ['length' => 64, 'fixed' => true, 'notnull' => false]);
        $send->addColumn('copy_sent_at', Types::DATETIME_IMMUTABLE, ['notnull' => false]);
        $send->addColumn('copy_error', Types::STRING, ['length' => 500, 'notnull' => false]);
        $send->addColumn('revoked_at', Types::DATETIME_IMMUTABLE, ['notnull' => false]);
        $send->addColumn('first_read_at', Types::DATETIME_IMMUTABLE, ['notnull' => false]);
        $send->addColumn('read_count', Types::INTEGER);
        $send->addColumn('pdf_key', Types::STRING, ['length' => 255]);
        $send->addColumn('pdf_sha256', Types::STRING, ['length' => 64, 'fixed' => true]);
        $send->addColumn('document_number', Types::STRING, ['length' => 80]);
        $send->addColumn('issuer_name', Types::STRING, ['length' => 255]);
        $send->addColumn('customer_name', Types::STRING, ['length' => 255]);
        $send->addColumn('amount_minor', Types::BIGINT);
        $send->addColumn('currency', Types::STRING, ['length' => 3, 'fixed' => true]);
        $send->addColumn('due_date', Types::DATE_IMMUTABLE);
        $send->setPrimaryKey(['id']);
        $send->addIndex(['tenant_id'], 'idx_invoice_send_tenant');
        $send->addIndex(['invoice_id'], 'idx_invoice_send_invoice');
        $send->addIndex(['tenant_id', 'status', 'created_at'], 'idx_invoice_send_status');
        $send->addUniqueIndex(['token_hash'], 'uniq_invoice_send_token');
        $send->addUniqueIndex(['copy_token_hash'], 'uniq_invoice_send_copy_token');
        $send->addForeignKeyConstraint('invoice', ['invoice_id'], ['id'], ['onDelete' => 'RESTRICT'], 'fk_invoice_send_invoice');
    }

    public function down(Schema $schema): void
    {
        $schema->dropTable('invoice_send');
    }
}
