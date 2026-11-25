<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Inbox\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Types\Types;
use Doctrine\Migrations\AbstractMigration;

/**
 * The items of Inbox: one per mail that arrived at a person's inbox address,
 * and the records that came of each.
 */
final class Version20261125120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create the inbox items and their outputs';
    }

    public function up(Schema $schema): void
    {
        $item = $schema->createTable('inbox_item');
        $item->addColumn('id', Types::GUID);
        $item->addColumn('mail_message_id', Types::GUID);
        $item->addColumn('owner_user_id', Types::GUID);
        $item->addColumn('received_at', Types::DATETIME_IMMUTABLE);
        $item->addColumn('state', Types::STRING, ['length' => 16]);
        $item->addColumn('state_changed_at', Types::DATETIME_IMMUTABLE);
        $item->addColumn('state_changed_by', Types::GUID, ['notnull' => false]);
        $item->addColumn('contact_id', Types::GUID, ['notnull' => false]);
        $item->addColumn('company_id', Types::GUID, ['notnull' => false]);
        $item->addColumn('match_basis', Types::STRING, ['length' => 16, 'notnull' => false]);
        $item->addColumn('forwarded', Types::BOOLEAN);
        $item->addColumn('original_from', Types::STRING, ['length' => 320, 'notnull' => false]);
        $item->addColumn('thread_key', Types::STRING, ['length' => 64, 'fixed' => true]);
        $item->addColumn('duplicate_of_purpose', Types::STRING, ['length' => 40, 'notnull' => false]);
        $item->addColumn('email_activity_id', Types::GUID, ['notnull' => false]);
        $item->addColumn('trashed_at', Types::DATETIME_IMMUTABLE, ['notnull' => false]);
        $item->addColumn('trashed_by', Types::GUID, ['notnull' => false]);
        $item->setPrimaryKey(['id']);
        $item->addUniqueIndex(['mail_message_id'], 'uniq_inbox_item_mail_message');
        $item->addIndex(['owner_user_id', 'state', 'received_at'], 'idx_inbox_item_owner_state');
        $item->addIndex(['owner_user_id', 'thread_key'], 'idx_inbox_item_owner_thread');

        $output = $schema->createTable('inbox_item_output');
        $output->addColumn('item_id', Types::GUID);
        $output->addColumn('kind', Types::STRING, ['length' => 40]);
        $output->addColumn('record_id', Types::GUID);
        $output->addColumn('added_at', Types::DATETIME_IMMUTABLE);
        $output->addColumn('added_by', Types::GUID);
        $output->setPrimaryKey(['item_id', 'kind', 'record_id']);
        $output->addForeignKeyConstraint('inbox_item', ['item_id'], ['id'], ['onDelete' => 'CASCADE'], 'fk_inbox_item_output_item');
    }

    public function down(Schema $schema): void
    {
        $schema->dropTable('inbox_item_output');
        $schema->dropTable('inbox_item');
    }
}
