<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Calendar\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Types\Types;
use Doctrine\Migrations\AbstractMigration;

/**
 * The calendar feeds of Calendar (plan 32c): a person's secret iCal addresses,
 * of which only the SHA-256 of the token is kept.
 */
final class Version20261122170000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create the calendar feeds';
    }

    public function up(Schema $schema): void
    {
        $table = $schema->createTable('calendar_feed');
        $table->addColumn('id', Types::GUID);
        $table->addColumn('user_id', Types::GUID);
        $table->addColumn('name', Types::STRING, ['length' => 80]);
        $table->addColumn('token_hash', Types::STRING, ['length' => 64]);
        $table->addColumn('created_at', Types::DATETIME_IMMUTABLE);
        $table->addColumn('last_fetched_at', Types::DATETIME_IMMUTABLE, ['notnull' => false]);
        $table->addColumn('revoked_at', Types::DATETIME_IMMUTABLE, ['notnull' => false]);
        $table->setPrimaryKey(['id']);
        $table->addIndex(['user_id'], 'idx_calendar_feed_user');
        $table->addUniqueIndex(['token_hash'], 'uniq_calendar_feed_token_hash');
    }

    public function down(Schema $schema): void
    {
        $schema->dropTable('calendar_feed');
    }
}
