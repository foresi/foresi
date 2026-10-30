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
 * Address book fix, step 3 of 3: drops party.email (party_email is canonical
 * now) and party_bank_account.is_primary (primary_flag replaces it). Going down
 * re-adds them nullable for the previous migration to fill.
 */
final class Version20261029000010 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Address book: drop party.email and party_bank_account.is_primary';
    }

    public function up(Schema $schema): void
    {
        $schema->getTable('party')->dropColumn('email');
        $schema->getTable('party_bank_account')->dropColumn('is_primary');
    }

    public function down(Schema $schema): void
    {
        $schema->getTable('party')->addColumn('email', Types::STRING, ['length' => 255, 'notnull' => false]);
        $schema->getTable('party_bank_account')->addColumn('is_primary', Types::BOOLEAN, ['notnull' => false]);
    }
}
