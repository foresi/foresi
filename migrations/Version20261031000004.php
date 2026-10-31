<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Types\Types;
use Doctrine\Migrations\AbstractMigration;

/** Remembers when an import last moved, so the health check can tell a stalled one from a slow one. */
final class Version20261031000004 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add import.progressed_at';
    }

    public function up(Schema $schema): void
    {
        $schema->getTable('import')->addColumn('progressed_at', Types::DATETIME_IMMUTABLE, ['notnull' => false]);
    }

    public function down(Schema $schema): void
    {
        $schema->getTable('import')->dropColumn('progressed_at');
    }
}
