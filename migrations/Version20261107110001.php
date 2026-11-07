<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Types\Types;
use Doctrine\Migrations\AbstractMigration;

/**
 * Invitations of people by e-mail, keeping only the hash of the link token, and
 * deactivated memberships. A new table and a nullable column: every existing
 * membership stays active.
 */
final class Version20261107110001 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add invitations and the deactivation of memberships';
    }

    public function up(Schema $schema): void
    {
        $invitation = $schema->createTable('invitation');
        $invitation->addColumn('id', Types::GUID);
        $invitation->addColumn('tenant_id', 'tenant_id');
        $invitation->addColumn('email', Types::STRING, ['length' => 255]);
        $invitation->addColumn('display_name', Types::STRING, ['length' => 255]);
        $invitation->addColumn('role', Types::STRING, ['length' => 32]);
        $invitation->addColumn('party_id', Types::GUID, ['notnull' => false]);
        $invitation->addColumn('invited_by', Types::GUID);
        $invitation->addColumn('locale', Types::STRING, ['length' => 2]);
        $invitation->addColumn('token_hash', Types::STRING, ['length' => 64]);
        $invitation->addColumn('created_at', Types::DATETIME_IMMUTABLE);
        $invitation->addColumn('expires_at', Types::DATETIME_IMMUTABLE);
        $invitation->addColumn('accepted_at', Types::DATETIME_IMMUTABLE, ['notnull' => false]);
        $invitation->addColumn('revoked_at', Types::DATETIME_IMMUTABLE, ['notnull' => false]);
        $invitation->setPrimaryKey(['id']);
        $invitation->addIndex(['tenant_id'], 'idx_invitation_tenant');
        $invitation->addIndex(['tenant_id', 'email'], 'idx_invitation_email');
        $invitation->addIndex(['party_id'], 'IDX_F11D61A2213C1059');
        $invitation->addUniqueIndex(['token_hash'], 'uniq_invitation_token');
        $invitation->addForeignKeyConstraint('party', ['party_id'], ['id'], [], 'fk_invitation_party');

        $schema->getTable('membership')->addColumn('disabled_at', Types::DATETIME_IMMUTABLE, ['notnull' => false]);
    }

    public function down(Schema $schema): void
    {
        $schema->getTable('membership')->dropColumn('disabled_at');
        $schema->dropTable('invitation');
    }
}
