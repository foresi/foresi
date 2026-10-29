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
 * Creates the legal entity and party tables. Built with the schema API (not
 * raw SQL) so it runs unchanged on SQLite and PostgreSQL.
 */
final class Version20261029000002 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create the legal_entity and party tables';
    }

    public function up(Schema $schema): void
    {
        $entity = $schema->createTable('legal_entity');
        $this->identity($entity);
        $entity->addColumn('name', Types::STRING, ['length' => 255]);
        $entity->addColumn('vat_status', Types::STRING, ['length' => 32]);
        $entity->addColumn('register_entry', Types::STRING, ['length' => 255]);
        $entity->addColumn('bank_accounts', Types::JSON);
        $this->registrationNumber($entity, true);
        $this->vatId($entity);
        $this->address($entity);
        $entity->setPrimaryKey(['id']);
        $entity->addIndex(['tenant_id'], 'idx_legal_entity_tenant');

        $party = $schema->createTable('party');
        $this->identity($party);
        $party->addColumn('kind', Types::STRING, ['length' => 32]);
        $party->addColumn('display_name', Types::STRING, ['length' => 255]);
        $party->addColumn('email', Types::STRING, ['length' => 255]);
        $party->addColumn('phone', Types::STRING, ['length' => 64]);
        $party->addColumn('is_customer', Types::BOOLEAN);
        $party->addColumn('is_supplier', Types::BOOLEAN);
        $this->registrationNumber($party, false);
        $this->vatId($party);
        $this->address($party);
        $party->setPrimaryKey(['id']);
        $party->addIndex(['tenant_id'], 'idx_party_tenant');
    }

    public function down(Schema $schema): void
    {
        $schema->dropTable('party');
        $schema->dropTable('legal_entity');
    }

    private function identity(Table $table): void
    {
        $table->addColumn('id', Types::INTEGER, ['autoincrement' => true]);
        $table->addColumn('tenant_id', 'tenant_id');
    }

    private function registrationNumber(Table $table, bool $required): void
    {
        $table->addColumn('registration_country', Types::STRING, ['length' => 2, 'fixed' => true, 'notnull' => $required]);
        $table->addColumn('registration_number', Types::STRING, ['length' => 64, 'notnull' => $required]);
    }

    private function vatId(Table $table): void
    {
        $table->addColumn('vat_id', 'vat_id', ['length' => 14, 'notnull' => false]);
    }

    private function address(Table $table): void
    {
        $table->addColumn('address_street', Types::STRING, ['length' => 255]);
        $table->addColumn('address_city', Types::STRING, ['length' => 128]);
        $table->addColumn('address_postal_code', Types::STRING, ['length' => 32]);
        $table->addColumn('address_country_code', Types::STRING, ['length' => 2, 'fixed' => true]);
    }
}
