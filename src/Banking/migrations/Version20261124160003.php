<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Banking\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Types\Types;
use Doctrine\Migrations\AbstractMigration;

/**
 * The last step of the move into Mail: the columns Mail keeps now leave the
 * bank tables, and every feed and bank mail names its Mail row. Going down, the
 * columns come back empty and optional for the step before to fill; the first
 * step makes them required and the dedup key unique again.
 */
final class Version20261124160003 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Drop the bank mail columns that moved into the Mail module';
    }

    public function up(Schema $schema): void
    {
        $feed = $schema->getTable('bank_feed');
        $feed->dropIndex('uniq_bank_feed_token');
        $feed->dropColumn('token_hash');
        $feed->dropColumn('token_sealed');
        $feed->modifyColumn('mail_inbound_address_id', ['notnull' => true]);
        $feed->addUniqueIndex(['mail_inbound_address_id'], 'uniq_bank_feed_address');

        $message = $schema->getTable('inbound_message');
        $message->dropIndex('uniq_inbound_message_dedup');
        foreach (['channel', 'dedup_key', 'raw_key', 'raw_sha256', 'size', 'from_address', 'subject', 'received_at'] as $column) {
            $message->dropColumn($column);
        }
        $message->modifyColumn('mail_message_id', ['notnull' => true]);
        $message->addUniqueIndex(['mail_message_id'], 'uniq_inbound_message_mail');
    }

    public function down(Schema $schema): void
    {
        $feed = $schema->getTable('bank_feed');
        $feed->dropIndex('uniq_bank_feed_address');
        $feed->modifyColumn('mail_inbound_address_id', ['notnull' => false]);
        $feed->addColumn('token_hash', Types::STRING, ['length' => 64, 'fixed' => true, 'notnull' => false]);
        $feed->addColumn('token_sealed', Types::TEXT, ['notnull' => false]);
        $feed->addUniqueIndex(['token_hash'], 'uniq_bank_feed_token');

        $message = $schema->getTable('inbound_message');
        $message->dropIndex('uniq_inbound_message_mail');
        $message->modifyColumn('mail_message_id', ['notnull' => false]);
        $message->addColumn('channel', Types::STRING, ['length' => 16, 'notnull' => false]);
        $message->addColumn('dedup_key', Types::STRING, ['length' => 64, 'fixed' => true, 'notnull' => false]);
        $message->addColumn('raw_key', Types::STRING, ['length' => 128, 'notnull' => false]);
        $message->addColumn('raw_sha256', Types::STRING, ['length' => 64, 'fixed' => true, 'notnull' => false]);
        $message->addColumn('size', Types::INTEGER, ['notnull' => false]);
        $message->addColumn('from_address', Types::STRING, ['length' => 320, 'notnull' => false]);
        $message->addColumn('subject', Types::STRING, ['length' => 255, 'notnull' => false]);
        $message->addColumn('received_at', Types::DATETIME_IMMUTABLE, ['notnull' => false]);
        // The unique dedup key comes back in the first step, once the keys are copied back and made unique.
    }
}
