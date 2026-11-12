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

    public const string ID = 'id';
    public const string TENANT_ID = 'tenant_id';
    public const string NAME = 'name';
    public const string VERSION = 'version';
    public const string OCCURRED_AT = 'occurred_at';
    public const string SUBJECT_TYPE = 'subject_type';
    public const string SUBJECT_ID = 'subject_id';
    public const string DATA = 'data';
    public const string CORRELATION_ID = 'correlation_id';
    public const string ACTOR_KIND = 'actor_kind';
    public const string USER_ID = 'user_id';
    public const string API_TOKEN_ID = 'api_token_id';
    public const string POSITION = 'position';

    public const string LISTENER = 'listener';
    public const string EVENT_ID = 'event_id';
    public const string CONSUMED_AT = 'consumed_at';

    /**
     * Adds the two tables to a schema. The tables hold no entity, so the ORM
     * schema tool learns of them here and `doctrine:schema:validate` keeps them.
     */
    public static function define(Schema $schema): void
    {
        if (!$schema->hasTable(self::LOG)) {
            $log = $schema->createTable(self::LOG);
            $log->addColumn(self::ID, Types::GUID);
            $log->addColumn(self::TENANT_ID, 'tenant_id');
            $log->addColumn(self::NAME, Types::STRING, ['length' => 128]);
            $log->addColumn(self::VERSION, Types::SMALLINT);
            $log->addColumn(self::OCCURRED_AT, Types::DATETIME_IMMUTABLE);
            $log->addColumn(self::SUBJECT_TYPE, Types::STRING, ['length' => 64]);
            $log->addColumn(self::SUBJECT_ID, Types::STRING, ['length' => 64]);
            $log->addColumn(self::DATA, Types::JSON);
            $log->addColumn(self::CORRELATION_ID, Types::STRING, ['length' => 64, 'notnull' => false]);
            $log->addColumn(self::ACTOR_KIND, Types::STRING, ['length' => 16]);
            $log->addColumn(self::USER_ID, Types::GUID, ['notnull' => false]);
            $log->addColumn(self::API_TOKEN_ID, Types::GUID, ['notnull' => false]);
            $log->addColumn(self::POSITION, Types::BIGINT, ['notnull' => false]);
            $log->setPrimaryKey([self::ID]);
            $log->addUniqueIndex([self::POSITION], 'uniq_event_log_position');
            $log->addIndex([self::TENANT_ID, self::POSITION], 'idx_event_log_tenant_position');
            $log->addIndex([self::OCCURRED_AT], 'idx_event_log_occurred');
        }

        if (!$schema->hasTable(self::CONSUMPTION)) {
            $consumption = $schema->createTable(self::CONSUMPTION);
            $consumption->addColumn(self::LISTENER, Types::STRING, ['length' => 190]);
            $consumption->addColumn(self::EVENT_ID, Types::GUID);
            $consumption->addColumn(self::CONSUMED_AT, Types::DATETIME_IMMUTABLE);
            $consumption->setPrimaryKey([self::LISTENER, self::EVENT_ID]);
            $consumption->addIndex([self::CONSUMED_AT], 'idx_event_consumption_consumed');
        }
    }
}
