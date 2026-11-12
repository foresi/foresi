<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Events\Infrastructure\Dbal;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Types\Types;

/**
 * The names of the event tables and their columns in one place, and the layout
 * of both. The log is append-only: a row is inserted once, and the relay only
 * ever fills in its position.
 */
final class EventLogTable
{
    public const string LOG = 'event_log';
    public const string CONSUMPTION = 'event_consumption';

    /**
     * Adds the two tables to a schema. The tables hold no entity, so the ORM
     * schema tool learns of them here and `doctrine:schema:validate` keeps them.
     */
    public static function define(Schema $schema): void
    {
        if (!$schema->hasTable(self::LOG)) {
            $log = $schema->createTable(self::LOG);
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
        }

        if (!$schema->hasTable(self::CONSUMPTION)) {
            $consumption = $schema->createTable(self::CONSUMPTION);
            $consumption->addColumn('listener', Types::STRING, ['length' => 190]);
            $consumption->addColumn('event_id', Types::GUID);
            $consumption->addColumn('consumed_at', Types::DATETIME_IMMUTABLE);
            $consumption->setPrimaryKey(['listener', 'event_id']);
            $consumption->addIndex(['consumed_at'], 'idx_event_consumption_consumed');
        }
    }
}
