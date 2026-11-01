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
 * A firm can have its own roles, invoicing values, invoicing e-mails and archive
 * state for a contact. Null means the shared value applies. Existing rows keep
 * their values and are invoicing overrides; existing e-mails name no firm, so
 * they stay shared.
 *
 * Rolling back deletes what the old tables cannot hold: e-mails of a firm, and
 * firm rows without invoicing values.
 */
final class Version20261101000005 extends AbstractMigration
{
    private const array INVOICING = ['invoicing_due_days', 'invoicing_currency', 'invoicing_language', 'invoicing_payment_method', 'invoicing_reverse_charge'];

    public function getDescription(): string
    {
        return 'Let firms override roles, invoicing values, invoicing e-mails and the archive state of a contact';
    }

    public function up(Schema $schema): void
    {
        $settings = $schema->getTable('party_firm_settings');
        $settings->addColumn('roles', Types::JSON, ['notnull' => false]);
        $settings->addColumn('archived_at', Types::DATETIME_IMMUTABLE, ['notnull' => false]);
        foreach (self::INVOICING as $column) {
            $settings->modifyColumn($column, ['notnull' => false]);
        }

        $email = $schema->getTable('party_email');
        $email->addColumn('legal_entity_id', Types::GUID, ['notnull' => false]);
        $email->addIndex(['legal_entity_id'], 'idx_party_email_firm');
        $email->addForeignKeyConstraint('legal_entity', ['legal_entity_id'], ['id'], ['onDelete' => 'RESTRICT'], 'fk_party_email_firm');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DELETE FROM party_email WHERE legal_entity_id IS NOT NULL');
        $this->addSql('DELETE FROM party_firm_settings WHERE invoicing_due_days IS NULL');

        $email = $schema->getTable('party_email');
        $email->removeForeignKey('fk_party_email_firm');
        $email->dropIndex('idx_party_email_firm');
        $email->dropColumn('legal_entity_id');

        $settings = $schema->getTable('party_firm_settings');
        $settings->dropColumn('roles');
        $settings->dropColumn('archived_at');
        foreach (self::INVOICING as $column) {
            $settings->modifyColumn($column, ['notnull' => true]);
        }
    }
}
