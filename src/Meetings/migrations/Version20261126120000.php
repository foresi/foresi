<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Meetings\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Types\Types;
use Doctrine\Migrations\AbstractMigration;

/**
 * The tables of Meetings (plan 38.2): the series and their members, the meetings
 * with their minutes, attendees and the records they are about. A person is a
 * membership id and a linked record a kind and an id, without a foreign key to
 * another module's table, so the module installs and goes on its own.
 */
final class Version20261126120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create the meeting series and meetings of Meetings';
    }

    public function up(Schema $schema): void
    {
        $table = $schema->createTable('meetings_series');
        $table->addColumn('id', Types::GUID);
        $table->addColumn('name', Types::STRING, ['length' => 120]);
        $table->addColumn('visibility', Types::STRING, ['length' => 16]);
        $table->addColumn('agenda_template', Types::TEXT);
        $table->addColumn('default_project_id', Types::GUID, ['notnull' => false]);
        $table->addColumn('archived_at', Types::DATETIME_IMMUTABLE, ['notnull' => false]);
        $table->addColumn('created_by', Types::GUID);
        $table->addColumn('created_at', Types::DATETIME_IMMUTABLE);
        $table->setPrimaryKey(['id']);
        $table->addUniqueIndex(['name'], 'uniq_meetings_series_name');

        $table = $schema->createTable('meetings_series_member');
        $table->addColumn('id', Types::GUID);
        $table->addColumn('series_id', Types::GUID);
        $table->addColumn('membership_id', Types::GUID);
        $table->addColumn('default_attendee', Types::BOOLEAN);
        $table->setPrimaryKey(['id']);
        $table->addUniqueIndex(['series_id', 'membership_id'], 'uniq_meetings_series_member');
        $table->addIndex(['membership_id'], 'idx_meetings_series_member_membership');
        $table->addForeignKeyConstraint('meetings_series', ['series_id'], ['id'], ['onDelete' => 'CASCADE'], 'fk_meetings_series_member_series');

        $table = $schema->createTable('meetings_meeting');
        $table->addColumn('id', Types::GUID);
        $table->addColumn('series_id', Types::GUID, ['notnull' => false]);
        $table->addColumn('title', Types::STRING, ['length' => 200]);
        $table->addColumn('held_on', Types::DATE_IMMUTABLE);
        $table->addColumn('starts_at', Types::STRING, ['length' => 5, 'notnull' => false]);
        $table->addColumn('minutes', Types::TEXT);
        $table->addColumn('version', Types::INTEGER);
        $table->addColumn('created_by', Types::GUID);
        $table->addColumn('created_at', Types::DATETIME_IMMUTABLE);
        $table->addColumn('deleted_at', Types::DATETIME_IMMUTABLE, ['notnull' => false]);
        $table->addColumn('deleted_by', Types::GUID, ['notnull' => false]);
        $table->setPrimaryKey(['id']);
        $table->addIndex(['held_on'], 'idx_meetings_meeting_held_on');
        $table->addIndex(['series_id'], 'idx_meetings_meeting_series');
        $table->addForeignKeyConstraint('meetings_series', ['series_id'], ['id'], ['onDelete' => 'RESTRICT'], 'fk_meetings_meeting_series');

        $table = $schema->createTable('meetings_attendee');
        $table->addColumn('id', Types::GUID);
        $table->addColumn('meeting_id', Types::GUID);
        $table->addColumn('membership_id', Types::GUID);
        $table->setPrimaryKey(['id']);
        $table->addUniqueIndex(['meeting_id', 'membership_id'], 'uniq_meetings_attendee');
        $table->addIndex(['membership_id'], 'idx_meetings_attendee_membership');
        $table->addForeignKeyConstraint('meetings_meeting', ['meeting_id'], ['id'], ['onDelete' => 'CASCADE'], 'fk_meetings_attendee_meeting');

        $table = $schema->createTable('meetings_link');
        $table->addColumn('id', Types::GUID);
        $table->addColumn('meeting_id', Types::GUID);
        $table->addColumn('kind', Types::STRING, ['length' => 40]);
        $table->addColumn('target_id', Types::GUID);
        $table->setPrimaryKey(['id']);
        $table->addUniqueIndex(['meeting_id', 'kind', 'target_id'], 'uniq_meetings_link');
        $table->addIndex(['kind', 'target_id'], 'idx_meetings_link_target');
        $table->addForeignKeyConstraint('meetings_meeting', ['meeting_id'], ['id'], ['onDelete' => 'CASCADE'], 'fk_meetings_link_meeting');
    }

    public function down(Schema $schema): void
    {
        $schema->dropTable('meetings_link');
        $schema->dropTable('meetings_attendee');
        $schema->dropTable('meetings_meeting');
        $schema->dropTable('meetings_series_member');
        $schema->dropTable('meetings_series');
    }
}
