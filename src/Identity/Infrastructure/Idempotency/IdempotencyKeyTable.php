<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Identity\Infrastructure\Idempotency;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Types\Types;

/**
 * The idempotency keys of the API (ADR 0032): one row per token, operation and
 * key, which is also its primary key, so inserting it is the lock.
 */
final class IdempotencyKeyTable
{
    public const string TABLE = 'api_idempotency_key';
    public const string TOKEN_ID = 'token_id';
    public const string OPERATION = 'operation';
    public const string IDEMPOTENCY_KEY = 'idempotency_key';
    public const string FINGERPRINT = 'fingerprint';
    public const string STATUS = 'status';
    public const string RESPONSE_STATUS = 'response_status';
    public const string RESPONSE_HEADERS = 'response_headers';
    public const string RESPONSE_BODY = 'response_body';
    public const string CREATED_AT = 'created_at';
    public const string COMPLETED_AT = 'completed_at';

    public const string STATUS_IN_FLIGHT = 'in_flight';
    public const string STATUS_COMPLETED = 'completed';

    public const int MAX_KEY = 255;
    public const int MAX_OPERATION = 120;

    /**
     * Adds the table to a schema. It holds no entity, so the ORM schema tool
     * learns of it here and doctrine:schema:validate keeps it.
     */
    public static function define(Schema $schema): void
    {
        if ($schema->hasTable(self::TABLE)) {
            return;
        }
        $table = $schema->createTable(self::TABLE);
        $table->addColumn(self::TOKEN_ID, Types::GUID);
        $table->addColumn(self::OPERATION, Types::STRING, ['length' => self::MAX_OPERATION]);
        $table->addColumn(self::IDEMPOTENCY_KEY, Types::STRING, ['length' => self::MAX_KEY]);
        $table->addColumn(self::FINGERPRINT, Types::STRING, ['length' => 64, 'fixed' => true]);
        $table->addColumn(self::STATUS, Types::STRING, ['length' => 16]);
        $table->addColumn(self::RESPONSE_STATUS, Types::SMALLINT, ['notnull' => false]);
        $table->addColumn(self::RESPONSE_HEADERS, Types::TEXT, ['notnull' => false]);
        $table->addColumn(self::RESPONSE_BODY, Types::TEXT, ['notnull' => false]);
        $table->addColumn(self::CREATED_AT, Types::DATETIME_IMMUTABLE);
        $table->addColumn(self::COMPLETED_AT, Types::DATETIME_IMMUTABLE, ['notnull' => false]);
        $table->setPrimaryKey([self::TOKEN_ID, self::OPERATION, self::IDEMPOTENCY_KEY]);
        $table->addIndex([self::CREATED_AT], 'idx_api_idempotency_key_created');
    }
}
