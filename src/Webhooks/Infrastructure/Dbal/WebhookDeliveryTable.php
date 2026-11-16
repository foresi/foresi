<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Webhooks\Infrastructure\Dbal;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Types\Types;

/**
 * The names of the delivery tables and their columns in one place, and the
 * layout of both. A delivery is one event, or one ping, for one endpoint, with
 * the body it is signed and sent with; an attempt is one request made for it.
 */
final class WebhookDeliveryTable
{
    public const string DELIVERY = 'webhook_delivery';
    public const string ATTEMPT = 'webhook_delivery_attempt';

    public const string ID = 'id';
    public const string TENANT_ID = 'tenant_id';
    public const string ENDPOINT_ID = 'endpoint_id';
    public const string EVENT_ID = 'event_id';
    public const string EVENT_TYPE = 'event_type';
    public const string BODY = 'body';
    public const string STATUS = 'status';
    public const string ATTEMPTS = 'attempts';
    public const string NEXT_ATTEMPT_AT = 'next_attempt_at';
    public const string LAST_STATUS_CODE = 'last_status_code';
    public const string KIND = 'kind';
    public const string CREATED_AT = 'created_at';
    public const string FINISHED_AT = 'finished_at';

    public const string DELIVERY_ID = 'delivery_id';
    public const string ATTEMPTED_AT = 'attempted_at';
    public const string DURATION_MS = 'duration_ms';
    public const string STATUS_CODE = 'status_code';
    public const string ERROR = 'error';
    public const string TRIGGER = 'trigger';

    public const string STATUS_PENDING = 'pending';
    public const string STATUS_SUCCEEDED = 'succeeded';
    public const string STATUS_FAILED = 'failed';

    public const string KIND_EVENT = 'event';
    public const string KIND_PING = 'ping';

    /**
     * Adds the two tables to a schema. The tables hold no entity, so the ORM
     * schema tool learns of them here and `doctrine:schema:validate` keeps them.
     */
    public static function define(Schema $schema): void
    {
        if (!$schema->hasTable(self::DELIVERY)) {
            $delivery = $schema->createTable(self::DELIVERY);
            $delivery->addColumn(self::ID, Types::GUID);
            $delivery->addColumn(self::TENANT_ID, 'tenant_id');
            $delivery->addColumn(self::ENDPOINT_ID, Types::GUID);
            $delivery->addColumn(self::EVENT_ID, Types::GUID);
            $delivery->addColumn(self::EVENT_TYPE, Types::STRING, ['length' => 128]);
            $delivery->addColumn(self::BODY, Types::TEXT);
            $delivery->addColumn(self::STATUS, Types::STRING, ['length' => 16]);
            $delivery->addColumn(self::ATTEMPTS, Types::SMALLINT);
            $delivery->addColumn(self::NEXT_ATTEMPT_AT, Types::DATETIME_IMMUTABLE, ['notnull' => false]);
            $delivery->addColumn(self::LAST_STATUS_CODE, Types::SMALLINT, ['notnull' => false]);
            $delivery->addColumn(self::KIND, Types::STRING, ['length' => 16]);
            $delivery->addColumn(self::CREATED_AT, Types::DATETIME_IMMUTABLE);
            $delivery->addColumn(self::FINISHED_AT, Types::DATETIME_IMMUTABLE, ['notnull' => false]);
            $delivery->setPrimaryKey([self::ID]);
            $delivery->addUniqueIndex([self::ENDPOINT_ID, self::EVENT_ID], 'uniq_webhook_delivery_endpoint_event');
            $delivery->addIndex([self::STATUS, self::NEXT_ATTEMPT_AT], 'idx_webhook_delivery_due');
            $delivery->addIndex([self::ENDPOINT_ID, self::ID], 'idx_webhook_delivery_endpoint');
        }

        if (!$schema->hasTable(self::ATTEMPT)) {
            $attempt = $schema->createTable(self::ATTEMPT);
            $attempt->addColumn(self::ID, Types::GUID);
            $attempt->addColumn(self::TENANT_ID, 'tenant_id');
            $attempt->addColumn(self::DELIVERY_ID, Types::GUID);
            $attempt->addColumn(self::ATTEMPTED_AT, Types::DATETIME_IMMUTABLE);
            $attempt->addColumn(self::DURATION_MS, Types::INTEGER);
            $attempt->addColumn(self::STATUS_CODE, Types::SMALLINT, ['notnull' => false]);
            $attempt->addColumn(self::ERROR, Types::STRING, ['length' => 32, 'notnull' => false]);
            $attempt->addColumn(self::TRIGGER, Types::STRING, ['length' => 16]);
            $attempt->setPrimaryKey([self::ID]);
            $attempt->addIndex([self::DELIVERY_ID, self::ATTEMPTED_AT], 'idx_webhook_attempt_delivery');
        }
    }
}
