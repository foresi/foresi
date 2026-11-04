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
 * The bank accounts of a firm as records. The next migration fills the table
 * from the list on the firm.
 */
final class Version20261103020001 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add the bank accounts of a firm as records';
    }

    public function up(Schema $schema): void
    {
        $account = $schema->createTable('firm_bank_account');
        $account->addColumn('id', Types::GUID);
        $account->addColumn('tenant_id', 'tenant_id');
        $account->addColumn('legal_entity_id', Types::GUID);
        $account->addColumn('iban', Types::STRING, ['length' => 34]);
        $account->addColumn('currency', Types::STRING, ['length' => 3]);
        $account->addColumn('label', Types::STRING, ['length' => 100]);
        $account->addColumn('primary_flag', Types::BOOLEAN, ['notnull' => false]);
        $account->addColumn('position', Types::INTEGER);
        $account->addColumn('archived_at', Types::DATETIME_IMMUTABLE, ['notnull' => false]);
        $account->setPrimaryKey(['id']);
        $account->addIndex(['tenant_id'], 'idx_firm_bank_account_tenant');
        $account->addUniqueIndex(['legal_entity_id', 'iban'], 'uniq_firm_bank_account_iban');
        $account->addUniqueIndex(['legal_entity_id', 'primary_flag'], 'uniq_firm_bank_account_primary');
        $account->addForeignKeyConstraint('legal_entity', ['legal_entity_id'], ['id'], ['onDelete' => 'RESTRICT'], 'fk_firm_bank_account_firm');
    }

    public function down(Schema $schema): void
    {
        $schema->dropTable('firm_bank_account');
    }
}
