<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Mail\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Types\Types;
use Doctrine\Migrations\AbstractMigration;

/**
 * One active address per person and purpose, by a plain unique index: the owner
 * is kept a second time in active_owner_user_id, set only while the address is
 * not revoked, so two requests at once cannot make two. Revoked addresses and the
 * ones without an owner (a bank feed) hold NULL, which a unique index never matches.
 */
final class Version20261125100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'One active inbound address per person and purpose';
    }

    public function up(Schema $schema): void
    {
        $table = $schema->getTable('mail_inbound_address');
        $table->addColumn('active_owner_user_id', Types::GUID, ['notnull' => false]);
        $table->addUniqueIndex(['active_owner_user_id', 'purpose'], 'uniq_mail_inbound_address_owner_purpose');
    }

    public function postUp(Schema $schema): void
    {
        $this->connection->executeStatement('UPDATE mail_inbound_address SET active_owner_user_id = owner_user_id WHERE revoked_at IS NULL AND owner_user_id IS NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $table = $schema->getTable('mail_inbound_address');
        $table->dropIndex('uniq_mail_inbound_address_owner_purpose');
        $table->dropColumn('active_owner_user_id');
    }
}
