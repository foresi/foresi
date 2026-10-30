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
 * Stores the bank accounts a VAT payer has published next to the unreliable
 * payer check, so the contact page never has to ask the register.
 */
final class Version20261029000011 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Address book: store the published bank accounts of the unreliable payer check';
    }

    public function up(Schema $schema): void
    {
        $schema->getTable('party')->addColumn('unreliable_payer_accounts', Types::JSON, ['notnull' => false]);
    }

    public function down(Schema $schema): void
    {
        $schema->getTable('party')->dropColumn('unreliable_payer_accounts');
    }
}
