<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Mail\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * One active address per person and purpose: a partial unique index, so two
 * requests at once cannot make two. Revoked addresses and the ones without an
 * owner (a bank feed) are not in it. Plain SQL, as both PostgreSQL and SQLite
 * know the partial index and the schema tool of SQLite does not write it.
 */
final class Version20261125100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'One active inbound address per person and purpose';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE UNIQUE INDEX uniq_mail_inbound_address_owner_purpose ON mail_inbound_address (owner_user_id, purpose) WHERE revoked_at IS NULL AND owner_user_id IS NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX uniq_mail_inbound_address_owner_purpose');
    }
}
