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
 * The last beat of the worker and of the schedule, which the health check and
 * the owners' notice read. A new table of the installation, no tenant column.
 */
final class Version20261106030001 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add the heartbeats of the worker and the schedule';
    }

    public function up(Schema $schema): void
    {
        $heartbeat = $schema->createTable('heartbeat');
        $heartbeat->addColumn('name', Types::STRING, ['length' => 32]);
        $heartbeat->addColumn('beat_at', Types::DATETIME_IMMUTABLE);
        $heartbeat->setPrimaryKey(['name']);
    }

    public function down(Schema $schema): void
    {
        $schema->dropTable('heartbeat');
    }
}
