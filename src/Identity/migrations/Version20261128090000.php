<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Identity\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Types\Types;
use Doctrine\Migrations\AbstractMigration;

/**
 * A change of the sign-in address waits for its link to be opened: the new address, the hash of
 * the link and when it expires. The session version ends every session signed in before a change.
 */
final class Version20261128090000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add the pending e-mail change and the session version of a user';
    }

    public function up(Schema $schema): void
    {
        $users = $schema->getTable('app_user');
        $users->addColumn('pending_email', Types::STRING, ['length' => 255, 'notnull' => false]);
        $users->addColumn('pending_email_token_hash', Types::STRING, ['length' => 64, 'notnull' => false]);
        $users->addColumn('pending_email_expires_at', Types::DATETIME_IMMUTABLE, ['notnull' => false]);
        $users->addColumn('session_version', Types::INTEGER, ['default' => 0]);
        $users->addIndex(['pending_email_token_hash'], 'idx_app_user_pending_email_token');
    }

    public function down(Schema $schema): void
    {
        $users = $schema->getTable('app_user');
        $users->dropIndex('idx_app_user_pending_email_token');
        $users->dropColumn('pending_email');
        $users->dropColumn('pending_email_token_hash');
        $users->dropColumn('pending_email_expires_at');
        $users->dropColumn('session_version');
    }
}
