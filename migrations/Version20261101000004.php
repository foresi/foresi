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
 * Per-firm settings of a contact: what a new invoice starts with when a given
 * firm issues it. One row per tenant, party and firm. The foreign key to the
 * firm restricts deletion: firms are archived, never deleted, and a firm with
 * settings cannot be removed even by hand. A new table, so nothing to backfill.
 */
final class Version20261101000004 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add party_firm_settings';
    }

    public function up(Schema $schema): void
    {
        $table = $schema->createTable('party_firm_settings');
        $table->addColumn('id', Types::GUID);
        $table->addColumn('tenant_id', 'tenant_id');
        $table->addColumn('party_id', Types::GUID);
        $table->addColumn('legal_entity_id', Types::GUID);
        $table->addColumn('invoicing_due_days', Types::INTEGER);
        $table->addColumn('invoicing_currency', Types::STRING, ['length' => 3, 'fixed' => true]);
        $table->addColumn('invoicing_language', Types::STRING, ['length' => 2, 'fixed' => true]);
        $table->addColumn('invoicing_payment_method', Types::STRING, ['length' => 32]);
        $table->addColumn('invoicing_reverse_charge', Types::BOOLEAN);
        $table->setPrimaryKey(['id']);
        $table->addIndex(['tenant_id'], 'idx_party_firm_settings_tenant');
        $table->addIndex(['party_id'], 'idx_party_firm_settings_party');
        $table->addIndex(['legal_entity_id'], 'idx_party_firm_settings_firm');
        $table->addUniqueIndex(['tenant_id', 'party_id', 'legal_entity_id'], 'uniq_party_firm_settings');
        $table->addForeignKeyConstraint('party', ['party_id'], ['id'], [], 'fk_party_firm_settings_party');
        $table->addForeignKeyConstraint('legal_entity', ['legal_entity_id'], ['id'], ['onDelete' => 'RESTRICT'], 'fk_party_firm_settings_firm');
    }

    public function down(Schema $schema): void
    {
        $schema->dropTable('party_firm_settings');
    }
}
