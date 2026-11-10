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
 * The code of the supply of a received document in domestic reverse charge,
 * which the control statement reports in B.1. Optional, so nothing existing
 * needs a value.
 */
final class Version20261110220001 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add the supply code of received documents';
    }

    public function up(Schema $schema): void
    {
        $schema->getTable('received_document')->addColumn('supply_code', Types::STRING, ['length' => 8, 'notnull' => false]);
    }

    public function down(Schema $schema): void
    {
        $schema->getTable('received_document')->dropColumn('supply_code');
    }
}
