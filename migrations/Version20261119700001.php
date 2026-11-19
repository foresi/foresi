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
 * One instance holds one firm (ADR 0039): what the firm had of its own for a
 * contact becomes the contact's own value, and the per-firm table goes. The
 * guard (Version20261118210001) made sure the database holds at most one
 * firm, so each contact has at most one row to take over.
 *
 * Each value the firm had replaces the contact's: the roles, the invoicing
 * defaults (all of them, as they were set together) and the archive state.
 * The firm's own invoicing e-mails replace the contact's shared invoicing
 * e-mails, which the firm did not send to; the general e-mail stays.
 *
 * Going down brings back an empty per-firm table and the firm column of the
 * e-mails; what was moved stays on the contact.
 */
final class Version20261119700001 extends AbstractMigration
{
    private const array INVOICING = [
        'invoicing_due_days',
        'invoicing_currency',
        'invoicing_language',
        'invoicing_payment_method',
        'invoicing_reverse_charge',
        'invoicing_hourly_rate_minor',
        'invoicing_hourly_rate_currency',
    ];

    public function getDescription(): string
    {
        return 'Fold the per-firm contact settings and e-mails into the contact';
    }

    public function up(Schema $schema): void
    {
        $row = 'FROM party_firm_settings s WHERE s.party_id = party.id';
        $this->addSql("UPDATE party SET roles = (SELECT s.roles {$row}) WHERE EXISTS (SELECT 1 {$row} AND s.roles IS NOT NULL)");

        $columns = array_map(static fn (string $column): string => "{$column} = (SELECT s.{$column} {$row})", self::INVOICING);
        // Rows from before the flag read as sending reminders.
        $columns[] = "invoicing_send_reminders = (SELECT COALESCE(s.invoicing_send_reminders, TRUE) {$row})";
        $this->addSql('UPDATE party SET '.implode(', ', $columns)." WHERE EXISTS (SELECT 1 {$row} AND s.invoicing_due_days IS NOT NULL)");

        $this->addSql("UPDATE party SET archived_at = (SELECT s.archived_at {$row}) WHERE archived_at IS NULL AND EXISTS (SELECT 1 {$row} AND s.archived_at IS NOT NULL)");

        $this->addSql("DELETE FROM party_email WHERE legal_entity_id IS NULL AND purpose <> 'general' AND party_id IN (SELECT o.party_id FROM party_email o WHERE o.legal_entity_id IS NOT NULL)");
        $this->addSql('UPDATE party_email SET legal_entity_id = NULL WHERE legal_entity_id IS NOT NULL');

        $email = $schema->getTable('party_email');
        $email->dropForeignKey('fk_party_email_firm');
        $email->dropIndex('idx_party_email_firm');
        $email->dropColumn('legal_entity_id');

        $schema->dropTable('party_firm_settings');
    }

    public function down(Schema $schema): void
    {
        $settings = $schema->createTable('party_firm_settings');
        $settings->addColumn('id', Types::GUID);
        $settings->addColumn('party_id', Types::GUID);
        $settings->addColumn('legal_entity_id', Types::GUID);
        $settings->addColumn('roles', Types::JSON, ['notnull' => false]);
        $settings->addColumn('invoicing_due_days', Types::INTEGER, ['notnull' => false]);
        $settings->addColumn('invoicing_currency', Types::STRING, ['length' => 3, 'fixed' => true, 'notnull' => false]);
        $settings->addColumn('invoicing_language', Types::STRING, ['length' => 2, 'fixed' => true, 'notnull' => false]);
        $settings->addColumn('invoicing_payment_method', Types::STRING, ['length' => 32, 'notnull' => false]);
        $settings->addColumn('invoicing_reverse_charge', Types::BOOLEAN, ['notnull' => false]);
        $settings->addColumn('invoicing_send_reminders', Types::BOOLEAN, ['notnull' => false]);
        $settings->addColumn('invoicing_hourly_rate_minor', Types::BIGINT, ['notnull' => false]);
        $settings->addColumn('invoicing_hourly_rate_currency', Types::STRING, ['length' => 3, 'fixed' => true, 'notnull' => false]);
        $settings->addColumn('archived_at', Types::DATETIME_IMMUTABLE, ['notnull' => false]);
        $settings->setPrimaryKey(['id']);
        $settings->addIndex(['party_id'], 'idx_party_firm_settings_party');
        $settings->addIndex(['legal_entity_id'], 'idx_party_firm_settings_firm');
        $settings->addUniqueIndex(['party_id', 'legal_entity_id'], 'uniq_party_firm_settings');
        $settings->addForeignKeyConstraint('party', ['party_id'], ['id'], [], 'fk_party_firm_settings_party');
        $settings->addForeignKeyConstraint('legal_entity', ['legal_entity_id'], ['id'], ['onDelete' => 'RESTRICT'], 'fk_party_firm_settings_firm');

        $email = $schema->getTable('party_email');
        $email->addColumn('legal_entity_id', Types::GUID, ['notnull' => false]);
        $email->addIndex(['legal_entity_id'], 'idx_party_email_firm');
        $email->addForeignKeyConstraint('legal_entity', ['legal_entity_id'], ['id'], ['onDelete' => 'RESTRICT'], 'fk_party_email_firm');
    }
}
