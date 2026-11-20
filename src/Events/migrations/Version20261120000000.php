<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Events\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Types\Types;
use Doctrine\Migrations\AbstractMigration;

/**
 * The baseline of Events: its 2 tables as the global migrations left
 * them (ADR 0034). A database migrated before the module packages moves onto
 * it with app:migrations:rebase, which runs none of this.
 */
final class Version20261120000000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create the tables of Events';
    }

    public function up(Schema $schema): void
    {
        $this->eventConsumption($schema);
        $this->eventLog($schema);
    }

    public function down(Schema $schema): void
    {
        $schema->dropTable('event_log');
        $schema->dropTable('event_consumption');
    }

    private function eventConsumption(Schema $schema): void
    {
        $table = $schema->createTable('event_consumption');
        $table->addColumn('listener', Types::STRING, ['length' => 190]);
        $table->addColumn('event_id', Types::GUID);
        $table->addColumn('consumed_at', Types::DATETIME_IMMUTABLE);
        $table->setPrimaryKey(['listener', 'event_id']);
        $table->addIndex(['consumed_at'], 'idx_event_consumption_consumed');
    }

    private function eventLog(Schema $schema): void
    {
        $table = $schema->createTable('event_log');
        $table->addColumn('id', Types::GUID);
        $table->addColumn('name', Types::STRING, ['length' => 128]);
        $table->addColumn('version', Types::SMALLINT);
        $table->addColumn('occurred_at', Types::DATETIME_IMMUTABLE);
        $table->addColumn('subject_type', Types::STRING, ['length' => 64]);
        $table->addColumn('subject_id', Types::STRING, ['length' => 64]);
        $table->addColumn('data', Types::JSON);
        $table->addColumn('correlation_id', Types::STRING, ['length' => 64, 'notnull' => false]);
        $table->addColumn('actor_kind', Types::STRING, ['length' => 16]);
        $table->addColumn('user_id', Types::GUID, ['notnull' => false]);
        $table->addColumn('api_token_id', Types::GUID, ['notnull' => false]);
        $table->addColumn('position', Types::BIGINT, ['notnull' => false]);
        $table->setPrimaryKey(['id']);
        $table->addIndex(['occurred_at'], 'idx_event_log_occurred');
        $table->addUniqueIndex(['position'], 'uniq_event_log_position');
    }
}
