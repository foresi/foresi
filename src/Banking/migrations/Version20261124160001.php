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
 * The first of three steps that move the bank mail into the Mail module: the
 * columns that name a feed's Mail address and a message's Mail message, empty
 * until the next step copies the rows. Going down, the moved columns, given
 * back by the steps after this one, are required again and the dedup key is
 * unique again.
 */
final class Version20261124160001 extends AbstractMigration
{
    /** The columns of the bank mail that Mail keeps from now on, required before the move. */
    public const array MOVED_MESSAGE_COLUMNS = ['channel', 'dedup_key', 'raw_key', 'raw_sha256', 'size', 'subject', 'received_at'];
    public const array MOVED_FEED_COLUMNS = ['token_hash', 'token_sealed'];

    public function getDescription(): string
    {
        return 'Add the Mail address of a bank feed and the Mail message of a bank mail';
    }

    public function up(Schema $schema): void
    {
        $schema->getTable('bank_feed')->addColumn('mail_inbound_address_id', Types::GUID, ['notnull' => false]);
        $schema->getTable('inbound_message')->addColumn('mail_message_id', Types::GUID, ['notnull' => false]);
    }

    public function down(Schema $schema): void
    {
        $feed = $schema->getTable('bank_feed');
        $feed->dropColumn('mail_inbound_address_id');
        foreach (self::MOVED_FEED_COLUMNS as $column) {
            $feed->modifyColumn($column, ['notnull' => true]);
        }
        $message = $schema->getTable('inbound_message');
        $message->dropColumn('mail_message_id');
        foreach (self::MOVED_MESSAGE_COLUMNS as $column) {
            $message->modifyColumn($column, ['notnull' => true]);
        }
        // Going down from the last step, the keys were copied back and made unique by the step after this one.
        if (!$message->hasIndex('uniq_inbound_message_dedup')) {
            $message->addUniqueIndex(['dedup_key'], 'uniq_inbound_message_dedup');
        }
    }
}
