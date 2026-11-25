<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Purchasing\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Types\Types;
use Doctrine\Migrations\AbstractMigration;

/**
 * The capture address of a person: the name they gave it and the id of the
 * inbound address Mail keeps. One active address per person: the column of the active owner holds the person while the address takes in mail and is empty once it is revoked, and it is unique. One firm per
 * instance (ADR 0039), so no firm id.
 */
final class Version20261125100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add the capture addresses of received documents';
    }

    public function up(Schema $schema): void
    {
        $table = $schema->createTable('purchasing_capture_address');
        $table->addColumn('id', Types::GUID);
        $table->addColumn('owner_user_id', Types::GUID);
        $table->addColumn('active_owner_user_id', Types::GUID, ['notnull' => false]);
        $table->addColumn('mail_address_id', Types::GUID);
        $table->addColumn('label', Types::STRING, ['length' => 80]);
        $table->addColumn('created_at', Types::DATETIME_IMMUTABLE);
        $table->addColumn('revoked_at', Types::DATETIME_IMMUTABLE, ['notnull' => false]);
        $table->addColumn('last_received_at', Types::DATETIME_IMMUTABLE, ['notnull' => false]);
        $table->setPrimaryKey(['id']);
        $table->addUniqueIndex(['mail_address_id'], 'uniq_purchasing_capture_address_mail');
        $table->addUniqueIndex(['active_owner_user_id'], 'uniq_purchasing_capture_address_active_owner');
    }

    public function down(Schema $schema): void
    {
        $schema->dropTable('purchasing_capture_address');
    }
}
