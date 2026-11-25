<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Work\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * When each task last changed, for the list of all tasks: a task changed before is
 * dated by the last entry of its history, or by when it was created when it has none.
 * The column is filled here and required by the next migration.
 */
final class Version20261125130000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add when each task last changed';
    }

    public function up(Schema $schema): void
    {
        $schema->getTable('task')->addColumn('updated_at', 'datetime_immutable', ['notnull' => false]);
    }

    public function postUp(Schema $schema): void
    {
        $this->connection->executeStatement(<<<'SQL'
            UPDATE task SET updated_at = COALESCE(
                (SELECT MAX(a.occurred_at) FROM audit_entry a WHERE a.entity_class = 'Foresi\Work\Domain\Task' AND a.entity_id = CAST(task.id AS VARCHAR(64))),
                created_at)
            SQL);
    }

    public function down(Schema $schema): void
    {
        $schema->getTable('task')->dropColumn('updated_at');
    }
}
