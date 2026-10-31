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
 * A party keeps its mobile phone and its website, so a contact imported from
 * another program loses neither. Existing parties start with none. Built with
 * the schema API so it runs unchanged on SQLite and PostgreSQL.
 */
final class Version20261031000002 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add party.mobile and party.website';
    }

    public function up(Schema $schema): void
    {
        $party = $schema->getTable('party');
        $party->addColumn('mobile', Types::STRING, ['length' => 64, 'default' => '']);
        $party->addColumn('website', Types::STRING, ['length' => 255, 'default' => '']);
    }

    public function down(Schema $schema): void
    {
        $party = $schema->getTable('party');
        $party->dropColumn('website');
        $party->dropColumn('mobile');
    }
}
