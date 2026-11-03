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
 * The mail server of a firm. A new table, so nothing to backfill.
 */
final class Version20261102000004 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add the mail server of a firm';
    }

    public function up(Schema $schema): void
    {
        $server = $schema->createTable('firm_mail_server');
        $server->addColumn('id', Types::GUID);
        $server->addColumn('tenant_id', 'tenant_id');
        $server->addColumn('legal_entity_id', Types::GUID);
        $server->addColumn('host', Types::STRING, ['length' => 253]);
        $server->addColumn('port', Types::INTEGER);
        $server->addColumn('encryption', Types::STRING, ['length' => 16]);
        $server->addColumn('username', Types::STRING, ['length' => 255]);
        $server->addColumn('secret_sealed', Types::TEXT);
        $server->addColumn('from_address', Types::STRING, ['length' => 254]);
        $server->addColumn('from_name', Types::STRING, ['length' => 255]);
        $server->addColumn('verified_at', Types::DATETIME_IMMUTABLE, ['notnull' => false]);
        $server->setPrimaryKey(['id']);
        $server->addIndex(['tenant_id'], 'idx_firm_mail_server_tenant');
        $server->addUniqueIndex(['legal_entity_id'], 'uniq_firm_mail_server_firm');
        $server->addForeignKeyConstraint('legal_entity', ['legal_entity_id'], ['id'], ['onDelete' => 'RESTRICT'], 'fk_firm_mail_server_firm');
    }

    public function down(Schema $schema): void
    {
        $schema->dropTable('firm_mail_server');
    }
}
