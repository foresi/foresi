<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Party\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Types\Types;
use Doctrine\Migrations\AbstractMigration;

/**
 * The baseline of Party: its 7 tables as the global migrations left
 * them (ADR 0034). A database migrated before the module packages moves onto
 * it with app:migrations:rebase, which runs none of this.
 */
final class Version20261120000000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create the tables of Party';
    }

    public function up(Schema $schema): void
    {
        $this->contactLink($schema);
        $this->firmBankAccount($schema);
        $this->legalEntity($schema);
        $this->party($schema);
        $this->partyAddress($schema);
        $this->partyBankAccount($schema);
        $this->partyEmail($schema);
    }

    public function down(Schema $schema): void
    {
        $schema->dropTable('party_email');
        $schema->dropTable('party_bank_account');
        $schema->dropTable('party_address');
        $schema->dropTable('party');
        $schema->dropTable('legal_entity');
        $schema->dropTable('firm_bank_account');
        $schema->dropTable('contact_link');
    }

    private function contactLink(Schema $schema): void
    {
        $table = $schema->createTable('contact_link');
        $table->addColumn('position', Types::STRING, ['length' => 100]);
        $table->addColumn('is_primary', Types::BOOLEAN);
        $table->addColumn('id', Types::GUID);
        $table->addColumn('company_id', Types::GUID);
        $table->addColumn('person_id', Types::GUID);
        $table->setPrimaryKey(['id']);
        $table->addIndex(['company_id'], 'idx_contact_link_company');
        $table->addIndex(['person_id'], 'idx_contact_link_person');
        $table->addUniqueIndex(['company_id', 'person_id'], 'uniq_contact_link_pair');
        $table->addForeignKeyConstraint('party', ['company_id'], ['id'], [], 'fk_contact_link_company');
        $table->addForeignKeyConstraint('party', ['person_id'], ['id'], [], 'fk_contact_link_person');
    }

    private function firmBankAccount(Schema $schema): void
    {
        $table = $schema->createTable('firm_bank_account');
        $table->addColumn('id', Types::GUID);
        $table->addColumn('iban', Types::STRING, ['length' => 34]);
        $table->addColumn('currency', Types::STRING, ['length' => 3]);
        $table->addColumn('label', Types::STRING, ['length' => 100]);
        $table->addColumn('primary_flag', Types::BOOLEAN, ['notnull' => false]);
        $table->addColumn('position', Types::INTEGER);
        $table->addColumn('archived_at', Types::DATETIME_IMMUTABLE, ['notnull' => false]);
        $table->setPrimaryKey(['id']);
        $table->addUniqueIndex(['iban'], 'uniq_firm_bank_account_iban');
        $table->addUniqueIndex(['primary_flag'], 'uniq_firm_bank_account_primary');
    }

    private function legalEntity(Schema $schema): void
    {
        $table = $schema->createTable('legal_entity');
        $table->addColumn('name', Types::STRING, ['length' => 255]);
        $table->addColumn('vat_status', Types::STRING, ['length' => 32]);
        $table->addColumn('register_entry', Types::STRING, ['length' => 255]);
        $table->addColumn('registration_country', Types::STRING, ['length' => 2, 'fixed' => true]);
        $table->addColumn('registration_number', Types::STRING, ['length' => 64]);
        $table->addColumn('vat_id', Types::STRING, ['length' => 14, 'notnull' => false]);
        $table->addColumn('address_street', Types::STRING, ['length' => 255]);
        $table->addColumn('address_city', Types::STRING, ['length' => 128]);
        $table->addColumn('address_postal_code', Types::STRING, ['length' => 32]);
        $table->addColumn('address_country_code', Types::STRING, ['length' => 2, 'fixed' => true]);
        $table->addColumn('id', Types::GUID);
        $table->addColumn('logo', Types::STRING, ['length' => 72, 'notnull' => false]);
        $table->addColumn('stamp', Types::STRING, ['length' => 72, 'notnull' => false]);
        $table->setPrimaryKey(['id']);
    }

    private function party(Schema $schema): void
    {
        $table = $schema->createTable('party');
        $table->addColumn('kind', Types::STRING, ['length' => 32]);
        $table->addColumn('display_name', Types::STRING, ['length' => 255]);
        $table->addColumn('phone', Types::STRING, ['length' => 64]);
        $table->addColumn('registration_country', Types::STRING, ['length' => 2, 'fixed' => true, 'notnull' => false]);
        $table->addColumn('registration_number', Types::STRING, ['length' => 64, 'notnull' => false]);
        $table->addColumn('vat_id', Types::STRING, ['length' => 14, 'notnull' => false]);
        $table->addColumn('roles', Types::JSON);
        $table->addColumn('archived_at', Types::DATETIME_IMMUTABLE, ['notnull' => false]);
        $table->addColumn('private_note', Types::TEXT);
        $table->addColumn('vies_result', Types::STRING, ['length' => 32, 'notnull' => false]);
        $table->addColumn('vies_checked_at', Types::DATETIME_IMMUTABLE, ['notnull' => false]);
        $table->addColumn('unreliable_payer_result', Types::STRING, ['length' => 32, 'notnull' => false]);
        $table->addColumn('unreliable_payer_checked_at', Types::DATETIME_IMMUTABLE, ['notnull' => false]);
        $table->addColumn('registration_key', Types::STRING, ['length' => 192, 'notnull' => false]);
        $table->addColumn('unreliable_payer_accounts', Types::JSON, ['notnull' => false]);
        $table->addColumn('mobile', Types::STRING, ['length' => 64, 'default' => '']);
        $table->addColumn('website', Types::STRING, ['length' => 255, 'default' => '']);
        $table->addColumn('id', Types::GUID);
        $table->setPrimaryKey(['id']);
        $table->addIndex(['registration_key'], 'idx_party_registration_key');
        $table->addIndex(['vat_id'], 'idx_party_vat_id');
    }

    private function partyAddress(Schema $schema): void
    {
        $table = $schema->createTable('party_address');
        $table->addColumn('type', Types::STRING, ['length' => 16]);
        $table->addColumn('label', Types::STRING, ['length' => 100]);
        $table->addColumn('address_street', Types::STRING, ['length' => 255]);
        $table->addColumn('address_city', Types::STRING, ['length' => 128]);
        $table->addColumn('address_postal_code', Types::STRING, ['length' => 32]);
        $table->addColumn('address_country_code', Types::STRING, ['length' => 2, 'fixed' => true]);
        $table->addColumn('billing_flag', Types::BOOLEAN, ['notnull' => false]);
        $table->addColumn('id', Types::GUID);
        $table->addColumn('party_id', Types::GUID);
        $table->setPrimaryKey(['id']);
        $table->addIndex(['party_id'], 'idx_party_address_party');
        $table->addUniqueIndex(['party_id', 'billing_flag'], 'uniq_party_address_billing');
        $table->addForeignKeyConstraint('party', ['party_id'], ['id'], [], 'fk_party_address_party');
    }

    private function partyBankAccount(Schema $schema): void
    {
        $table = $schema->createTable('party_bank_account');
        $table->addColumn('iban', Types::STRING, ['length' => 34]);
        $table->addColumn('bic', Types::STRING, ['length' => 11, 'notnull' => false]);
        $table->addColumn('label', Types::STRING, ['length' => 100]);
        $table->addColumn('primary_flag', Types::BOOLEAN, ['notnull' => false]);
        $table->addColumn('id', Types::GUID);
        $table->addColumn('party_id', Types::GUID);
        $table->setPrimaryKey(['id']);
        $table->addIndex(['party_id'], 'idx_party_bank_account_party');
        $table->addUniqueIndex(['party_id', 'primary_flag'], 'uniq_party_bank_account_primary');
        $table->addForeignKeyConstraint('party', ['party_id'], ['id'], [], 'fk_party_bank_account_party');
    }

    private function partyEmail(Schema $schema): void
    {
        $table = $schema->createTable('party_email');
        $table->addColumn('address', Types::STRING, ['length' => 254]);
        $table->addColumn('purpose', Types::STRING, ['length' => 16]);
        $table->addColumn('address_key', Types::STRING, ['length' => 254, 'default' => '']);
        $table->addColumn('id', Types::GUID);
        $table->addColumn('party_id', Types::GUID);
        $table->setPrimaryKey(['id']);
        $table->addIndex(['address_key'], 'idx_party_email_key');
        $table->addIndex(['party_id'], 'idx_party_email_party');
        $table->addForeignKeyConstraint('party', ['party_id'], ['id'], [], 'fk_party_email_party');
    }
}
