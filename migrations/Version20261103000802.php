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
 * Reminders of overdue invoices: a firm's settings, a send's purpose and step,
 * unique per invoice, and the contact's flag, shared and per firm. Existing sends
 * are invoices; existing contacts are reminded. A firm row from before the flag
 * holds null, which reads as on.
 */
final class Version20261103000802 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add reminder settings, the purpose and step of a send, and the contact reminder flag';
    }

    public function up(Schema $schema): void
    {
        $reminders = $schema->createTable('firm_reminders');
        $reminders->addColumn('id', Types::GUID);
        $reminders->addColumn('tenant_id', 'tenant_id');
        $reminders->addColumn('legal_entity_id', Types::GUID);
        $reminders->addColumn('enabled', Types::BOOLEAN, ['default' => false]);
        $reminders->addColumn('enabled_on', Types::DATE_IMMUTABLE, ['notnull' => false]);
        $reminders->addColumn('first_after_days', Types::SMALLINT);
        $reminders->addColumn('every_days', Types::SMALLINT);
        $reminders->addColumn('minimum_minor', Types::BIGINT, ['notnull' => false]);
        $reminders->addColumn('minimum_currency', Types::STRING, ['length' => 3, 'fixed' => true, 'notnull' => false]);
        $reminders->setPrimaryKey(['id']);
        $reminders->addIndex(['tenant_id'], 'idx_firm_reminders_tenant');
        $reminders->addUniqueIndex(['legal_entity_id'], 'uniq_firm_reminders_firm');
        $reminders->addForeignKeyConstraint('legal_entity', ['legal_entity_id'], ['id'], ['onDelete' => 'RESTRICT'], 'fk_firm_reminders_firm');

        $send = $schema->getTable('invoice_send');
        $send->addColumn('purpose', Types::STRING, ['length' => 16, 'default' => 'invoice']);
        $send->addColumn('reminder_step', Types::SMALLINT, ['notnull' => false]);
        $send->addUniqueIndex(['tenant_id', 'invoice_id', 'reminder_step'], 'uniq_send_reminder_step');

        $schema->getTable('party')->addColumn('invoicing_send_reminders', Types::BOOLEAN, ['default' => true]);
        $schema->getTable('party_firm_settings')->addColumn('invoicing_send_reminders', Types::BOOLEAN, ['notnull' => false]);
    }

    public function down(Schema $schema): void
    {
        $schema->getTable('party_firm_settings')->dropColumn('invoicing_send_reminders');
        $schema->getTable('party')->dropColumn('invoicing_send_reminders');

        $send = $schema->getTable('invoice_send');
        $send->dropIndex('uniq_send_reminder_step');
        $send->dropColumn('reminder_step');
        $send->dropColumn('purpose');

        $schema->dropTable('firm_reminders');
    }
}
