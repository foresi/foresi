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
 * Creates the user and membership tables (index names follow the ORM's
 * generated ones, so the schema stays in sync with the mapping). Built with the schema API (not raw
 * SQL) so it runs unchanged on SQLite and PostgreSQL.
 */
final class Version20261029000003 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create the app_user and membership tables';
    }

    public function up(Schema $schema): void
    {
        $user = $schema->createTable('app_user');
        $user->addColumn('id', Types::INTEGER, ['autoincrement' => true]);
        $user->addColumn('email', Types::STRING, ['length' => 255]);
        $user->addColumn('display_name', Types::STRING, ['length' => 255]);
        $user->addColumn('locale', Types::STRING, ['length' => 2]);
        $user->addColumn('password_hash', Types::STRING, ['length' => 255]);
        $user->setPrimaryKey(['id']);
        $user->addUniqueIndex(['email'], 'UNIQ_88BDF3E9E7927C74');

        $membership = $schema->createTable('membership');
        $membership->addColumn('id', Types::INTEGER, ['autoincrement' => true]);
        $membership->addColumn('tenant_id', 'tenant_id');
        $membership->addColumn('user_id', Types::INTEGER);
        $membership->addColumn('party_id', Types::INTEGER, ['notnull' => false]);
        $membership->addColumn('role', Types::STRING, ['length' => 32]);
        $membership->setPrimaryKey(['id']);
        $membership->addIndex(['tenant_id'], 'idx_membership_tenant');
        $membership->addUniqueIndex(['tenant_id', 'user_id'], 'uniq_membership_tenant_user');
        $membership->addForeignKeyConstraint('app_user', ['user_id'], ['id'], [], 'fk_membership_user');
        $membership->addForeignKeyConstraint('party', ['party_id'], ['id'], [], 'fk_membership_party');
    }

    public function down(Schema $schema): void
    {
        $schema->dropTable('membership');
        $schema->dropTable('app_user');
    }
}
