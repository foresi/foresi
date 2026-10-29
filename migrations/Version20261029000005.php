<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Schema\Table;
use Doctrine\DBAL\Types\Types;
use Doctrine\Migrations\AbstractMigration;

/**
 * Address book, step 1 of 3: adds the new party columns (nullable for now, the
 * next migration fills them) and the address, e-mail, bank account and contact
 * link tables. Schema API only, so it runs unchanged on SQLite and PostgreSQL.
 */
final class Version20261029000005 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Address book: new party columns and the party_address, party_email, party_bank_account and contact_link tables';
    }

    public function up(Schema $schema): void
    {
        $party = $schema->getTable('party');
        $party->addColumn('roles', Types::JSON, ['notnull' => false]);
        $party->addColumn('archived_at', Types::DATETIME_IMMUTABLE, ['notnull' => false]);
        $party->addColumn('private_note', Types::TEXT, ['notnull' => false]);
        $party->addColumn('invoicing_due_days', Types::INTEGER, ['notnull' => false]);
        $party->addColumn('invoicing_currency', Types::STRING, ['length' => 3, 'fixed' => true, 'notnull' => false]);
        $party->addColumn('invoicing_language', Types::STRING, ['length' => 2, 'fixed' => true, 'notnull' => false]);
        $party->addColumn('invoicing_payment_method', Types::STRING, ['length' => 32, 'notnull' => false]);
        $party->addColumn('invoicing_reverse_charge', Types::BOOLEAN, ['notnull' => false]);
        foreach (['vies_', 'unreliable_payer_'] as $prefix) {
            $party->addColumn($prefix.'result', Types::STRING, ['length' => 32, 'notnull' => false]);
            $party->addColumn($prefix.'checked_at', Types::DATETIME_IMMUTABLE, ['notnull' => false]);
        }

        $address = $this->child($schema, 'party_address');
        $address->addColumn('type', Types::STRING, ['length' => 16]);
        $address->addColumn('label', Types::STRING, ['length' => 100]);
        $address->addColumn('address_street', Types::STRING, ['length' => 255]);
        $address->addColumn('address_city', Types::STRING, ['length' => 128]);
        $address->addColumn('address_postal_code', Types::STRING, ['length' => 32]);
        $address->addColumn('address_country_code', Types::STRING, ['length' => 2, 'fixed' => true]);
        // True on the billing address, NULL elsewhere: NULLs never collide, so a party gets one billing address.
        $address->addColumn('billing_flag', Types::BOOLEAN, ['notnull' => false]);
        $address->addUniqueIndex(['party_id', 'billing_flag'], 'uniq_party_address_billing');

        $email = $this->child($schema, 'party_email');
        $email->addColumn('address', Types::STRING, ['length' => 254]);
        $email->addColumn('purpose', Types::STRING, ['length' => 16]);

        $bank = $this->child($schema, 'party_bank_account');
        $bank->addColumn('iban', Types::STRING, ['length' => 34]);
        $bank->addColumn('bic', Types::STRING, ['length' => 11, 'notnull' => false]);
        $bank->addColumn('label', Types::STRING, ['length' => 100]);
        $bank->addColumn('is_primary', Types::BOOLEAN);

        $link = $schema->createTable('contact_link');
        $link->addColumn('id', Types::INTEGER, ['autoincrement' => true]);
        $link->addColumn('tenant_id', 'tenant_id');
        $link->addColumn('company_id', Types::INTEGER);
        $link->addColumn('person_id', Types::INTEGER);
        $link->addColumn('position', Types::STRING, ['length' => 100]);
        $link->addColumn('is_primary', Types::BOOLEAN);
        $link->setPrimaryKey(['id']);
        $link->addIndex(['tenant_id'], 'idx_contact_link_tenant');
        $link->addIndex(['company_id'], 'idx_contact_link_company');
        $link->addIndex(['person_id'], 'idx_contact_link_person');
        $link->addUniqueIndex(['tenant_id', 'company_id', 'person_id'], 'uniq_contact_link_pair');
        $link->addForeignKeyConstraint('party', ['company_id'], ['id'], [], 'fk_contact_link_company');
        $link->addForeignKeyConstraint('party', ['person_id'], ['id'], [], 'fk_contact_link_person');
    }

    public function down(Schema $schema): void
    {
        $schema->dropTable('contact_link');
        $schema->dropTable('party_bank_account');
        $schema->dropTable('party_email');
        $schema->dropTable('party_address');

        $party = $schema->getTable('party');
        // The previous migration's down() has refilled the legacy columns by now.
        foreach (['is_customer', 'is_supplier', 'address_street', 'address_city', 'address_postal_code', 'address_country_code'] as $column) {
            $party->getColumn($column)->setNotnull(true);
        }
        foreach (['roles', 'archived_at', 'private_note', 'invoicing_due_days', 'invoicing_currency', 'invoicing_language', 'invoicing_payment_method', 'invoicing_reverse_charge'] as $column) {
            $party->dropColumn($column);
        }
        foreach (['vies_', 'unreliable_payer_'] as $prefix) {
            foreach (['result', 'checked_at'] as $column) {
                $party->dropColumn($prefix.$column);
            }
        }
    }

    private function child(Schema $schema, string $name): Table
    {
        $table = $schema->createTable($name);
        $table->addColumn('id', Types::INTEGER, ['autoincrement' => true]);
        $table->addColumn('tenant_id', 'tenant_id');
        $table->addColumn('party_id', Types::INTEGER);
        $table->setPrimaryKey(['id']);
        $table->addIndex(['tenant_id'], 'idx_'.$name.'_tenant');
        $table->addIndex(['party_id'], 'idx_'.$name.'_party');
        $table->addForeignKeyConstraint('party', ['party_id'], ['id'], [], 'fk_'.$name.'_party');

        return $table;
    }
}
