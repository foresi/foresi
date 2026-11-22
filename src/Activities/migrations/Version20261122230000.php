<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Activities\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Types\Types;
use Doctrine\Migrations\AbstractMigration;
use Symfony\Component\Uid\Uuid;

/**
 * The tables of Activities, and its four types (P3 of plan 32): a meeting of
 * 60 minutes and a call of 15, a note with no time that is done when it is
 * written, and a task on a date. They have no name of their own, so each shows
 * under its key's name in the person's language until someone renames it.
 */
final class Version20261122230000 extends AbstractMigration
{
    /** @var list<array{string, string, int|null}> key, timing, default duration */
    private const array TYPES = [
        ['meeting', 'timed', 60],
        ['call', 'timed', 15],
        ['note', 'none', null],
        ['task', 'date', null],
    ];

    public function getDescription(): string
    {
        return 'Create the tables of Activities with the meeting, call, note and task types';
    }

    public function up(Schema $schema): void
    {
        $table = $schema->createTable('activities_type');
        $table->addColumn('id', Types::GUID);
        $table->addColumn('type_key', Types::STRING, ['length' => 40]);
        $table->addColumn('name', Types::STRING, ['length' => 80, 'notnull' => false]);
        $table->addColumn('timing', Types::STRING, ['length' => 8]);
        $table->addColumn('default_duration_minutes', Types::INTEGER, ['notnull' => false]);
        $table->addColumn('archived_at', Types::DATETIME_IMMUTABLE, ['notnull' => false]);
        $table->addColumn('created_at', Types::DATETIME_IMMUTABLE);
        $table->setPrimaryKey(['id']);
        $table->addUniqueIndex(['type_key'], 'uniq_activities_type_key');

        $table = $schema->createTable('activities_activity');
        $table->addColumn('id', Types::GUID);
        $table->addColumn('type_id', Types::GUID);
        $table->addColumn('subject', Types::STRING, ['length' => 200]);
        $table->addColumn('body', Types::TEXT);
        $table->addColumn('planned_on', Types::DATE_IMMUTABLE, ['notnull' => false]);
        $table->addColumn('planned_at', Types::DATETIME_IMMUTABLE, ['notnull' => false]);
        $table->addColumn('duration_minutes', Types::INTEGER, ['notnull' => false]);
        $table->addColumn('done_at', Types::DATETIME_IMMUTABLE, ['notnull' => false]);
        $table->addColumn('outcome', Types::STRING, ['length' => 1000, 'notnull' => false]);
        $table->addColumn('owner_user_id', Types::GUID);
        $table->addColumn('created_by', Types::GUID);
        $table->addColumn('created_at', Types::DATETIME_IMMUTABLE);
        $table->addColumn('updated_at', Types::DATETIME_IMMUTABLE);
        $table->addColumn('trashed_at', Types::DATETIME_IMMUTABLE, ['notnull' => false]);
        $table->addColumn('trashed_by', Types::GUID, ['notnull' => false]);
        $table->setPrimaryKey(['id']);
        $table->addIndex(['owner_user_id'], 'idx_activities_activity_owner');

        $table = $schema->createTable('activities_activity_link');
        $table->addColumn('activity_id', Types::GUID);
        $table->addColumn('kind', Types::STRING, ['length' => 40]);
        $table->addColumn('target_id', Types::GUID);
        $table->setPrimaryKey(['activity_id', 'kind', 'target_id']);
        $table->addIndex(['kind', 'target_id'], 'idx_activities_activity_link_target');
        $table->addForeignKeyConstraint('activities_activity', ['activity_id'], ['id'], ['onDelete' => 'CASCADE'], 'fk_activities_activity_link_activity');
    }

    public function postUp(Schema $schema): void
    {
        // A dry run creates no table to fill.
        if (!$this->connection->createSchemaManager()->tablesExist(['activities_type'])) {
            return;
        }
        foreach (self::TYPES as [$key, $timing, $duration]) {
            $this->connection->insert('activities_type', [
                'id' => Uuid::v7()->toRfc4122(),
                'type_key' => $key,
                'name' => null,
                'timing' => $timing,
                'default_duration_minutes' => $duration,
                'archived_at' => null,
                'created_at' => new \DateTimeImmutable(),
            ], ['created_at' => Types::DATETIME_IMMUTABLE]);
        }
    }

    public function down(Schema $schema): void
    {
        $schema->dropTable('activities_activity_link');
        $schema->dropTable('activities_activity');
        $schema->dropTable('activities_type');
    }
}
