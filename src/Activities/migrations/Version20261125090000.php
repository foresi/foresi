<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Activities\Migrations;

use Doctrine\DBAL\ParameterType;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Types\Types;
use Doctrine\Migrations\AbstractMigration;
use Foresi\Shared\Domain\Id;

/**
 * The fifth seeded type, an e-mail: no time of its own, done when it is
 * recorded, as a note is. It has no name of its own, so it shows under its
 * key's name in the person's language until someone renames it. An instance
 * that has it already keeps it as it is.
 */
final class Version20261125090000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add the e-mail activity type';
    }

    public function up(Schema $schema): void
    {
        // The row goes in in postUp(), once the table exists: nothing is read while migrations are planned.
    }

    public function postUp(Schema $schema): void
    {
        // A dry run creates no table to fill.
        if (!$this->connection->createSchemaManager()->tablesExist(['activities_type'])) {
            return;
        }
        $this->connection->executeStatement(
            "INSERT INTO activities_type (id, type_key, name, timing, default_duration_minutes, archived_at, created_at) SELECT ?, 'email', NULL, 'none', NULL, NULL, ? "
            ."WHERE NOT EXISTS (SELECT 1 FROM activities_type WHERE type_key = 'email')",
            [Id::generate(), new \DateTimeImmutable()],
            [ParameterType::STRING, Types::DATETIME_IMMUTABLE],
        );
    }

    public function down(Schema $schema): void
    {
        // The type stays: activities may be of it.
    }
}
