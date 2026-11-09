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
 * The e-mail preferences of notifications: per person and kind of event at
 * once, in the daily digest or off, the digest hour and no e-mail at all.
 */
final class Version20261109150001 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add notification preferences';
    }

    public function up(Schema $schema): void
    {
        $preference = $schema->createTable('notification_preference');
        $preference->addColumn('id', Types::GUID);
        $preference->addColumn('tenant_id', 'tenant_id');
        $preference->addColumn('membership_id', Types::GUID);
        foreach (['mentioned', 'assigned', 'commented', 'status_changed', 'shared'] as $kind) {
            $preference->addColumn($kind, Types::STRING, ['length' => 16]);
        }
        $preference->addColumn('digest_hour', Types::SMALLINT);
        $preference->addColumn('no_email', Types::BOOLEAN);
        $preference->addColumn('last_digest_at', Types::DATETIME_IMMUTABLE, ['notnull' => false]);
        $preference->setPrimaryKey(['id']);
        $preference->addIndex(['tenant_id'], 'idx_notification_preference_tenant');
        $preference->addUniqueIndex(['membership_id'], 'uniq_notification_preference_membership');
        $preference->addForeignKeyConstraint('membership', ['membership_id'], ['id'], ['onDelete' => 'CASCADE'], 'fk_notification_preference_membership');
    }

    public function down(Schema $schema): void
    {
        $schema->dropTable('notification_preference');
    }
}
