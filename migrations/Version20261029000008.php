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
 * Address book fix, step 1 of 3: the normalised registration number (for
 * duplicate lookups in SQL; 192 characters because upper-casing can expand a
 * character, ß becomes SS), the primary flag of bank accounts with its unique
 * index (true on the primary account, NULL elsewhere, so NULLs never collide on
 * SQLite or PostgreSQL) and lookup indexes. Schema API only.
 */
final class Version20261029000008 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Address book: registration_key, bank account primary_flag and lookup indexes';
    }

    public function up(Schema $schema): void
    {
        $party = $schema->getTable('party');
        $party->addColumn('registration_key', Types::STRING, ['length' => 192, 'notnull' => false]);
        $party->addIndex(['tenant_id', 'registration_key'], 'idx_party_registration_key');
        $party->addIndex(['tenant_id', 'vat_id'], 'idx_party_vat_id');

        $bank = $schema->getTable('party_bank_account');
        $bank->addColumn('primary_flag', Types::BOOLEAN, ['notnull' => false]);
        $bank->addUniqueIndex(['party_id', 'primary_flag'], 'uniq_party_bank_account_primary');
    }

    public function down(Schema $schema): void
    {
        $bank = $schema->getTable('party_bank_account');
        $bank->dropIndex('uniq_party_bank_account_primary');
        $bank->dropColumn('primary_flag');
        // The next migration's down() has refilled these by now.
        $bank->getColumn('is_primary')->setNotnull(true);

        $party = $schema->getTable('party');
        $party->dropIndex('idx_party_vat_id');
        $party->dropIndex('idx_party_registration_key');
        $party->dropColumn('registration_key');
        $party->getColumn('email')->setNotnull(true);
    }
}
