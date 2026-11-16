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
 * The append-only event log and the record of what each asynchronous listener
 * has consumed. A log row is inserted with the change it describes and gets its
 * position from the relay after the commit, so the position is null until then.
 * Built with the schema API so it runs unchanged on SQLite and PostgreSQL.
 */
final class Version20261112000002 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add the event log and the record of consumed events';
    }

    public function up(Schema $schema): void
    {
        $log = $schema->createTable('event_log');
        $log->addColumn('id', Types::GUID);
        $log->addColumn('tenant_id', 'tenant_id');
        $log->addColumn('name', Types::STRING, ['length' => 128]);
        $log->addColumn('version', Types::SMALLINT);
        $log->addColumn('occurred_at', Types::DATETIME_IMMUTABLE);
        $log->addColumn('subject_type', Types::STRING, ['length' => 64]);
        $log->addColumn('subject_id', Types::STRING, ['length' => 64]);
        $log->addColumn('data', Types::JSON);
        $log->addColumn('correlation_id', Types::STRING, ['length' => 64, 'notnull' => false]);
        $log->addColumn('actor_kind', Types::STRING, ['length' => 16]);
        $log->addColumn('user_id', Types::GUID, ['notnull' => false]);
        $log->addColumn('api_token_id', Types::GUID, ['notnull' => false]);
        $log->addColumn('position', Types::BIGINT, ['notnull' => false]);
        $log->setPrimaryKey(['id']);
        $log->addUniqueIndex(['position'], 'uniq_event_log_position');
        $log->addIndex(['tenant_id', 'position'], 'idx_event_log_tenant_position');
        $log->addIndex(['occurred_at'], 'idx_event_log_occurred');

        $consumption = $schema->createTable('event_consumption');
        $consumption->addColumn('listener', Types::STRING, ['length' => 190]);
        $consumption->addColumn('event_id', Types::GUID);
        $consumption->addColumn('consumed_at', Types::DATETIME_IMMUTABLE);
        $consumption->setPrimaryKey(['listener', 'event_id']);
        $consumption->addIndex(['consumed_at'], 'idx_event_consumption_consumed');
    }

    public function down(Schema $schema): void
    {
        $schema->dropTable('event_consumption');
        $schema->dropTable('event_log');
    }
}
