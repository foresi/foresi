<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Audit\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Types\Types;
use Doctrine\Migrations\AbstractMigration;

/**
 * The baseline of Audit: its 1 table as the global migrations left
 * them (ADR 0034). A database migrated before the module packages moves onto
 * it with app:migrations:rebase, which runs none of this.
 */
final class Version20261120000000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create the table of Audit';
    }

    public function up(Schema $schema): void
    {
        $this->auditEntry($schema);
    }

    public function down(Schema $schema): void
    {
        $schema->dropTable('audit_entry');
    }

    private function auditEntry(Schema $schema): void
    {
        $table = $schema->createTable('audit_entry');
        $table->addColumn('entity_class', Types::STRING, ['length' => 255]);
        $table->addColumn('entity_id', Types::STRING, ['length' => 64]);
        $table->addColumn('action', Types::STRING, ['length' => 16]);
        $table->addColumn('changes', Types::JSON);
        $table->addColumn('occurred_at', Types::DATETIME_IMMUTABLE);
        $table->addColumn('correlation_id', Types::STRING, ['length' => 64, 'notnull' => false]);
        $table->addColumn('id', Types::GUID);
        $table->addColumn('user_id', Types::GUID, ['notnull' => false]);
        $table->addColumn('root_class', Types::STRING, ['length' => 255, 'default' => '']);
        $table->addColumn('root_id', Types::STRING, ['length' => 64, 'default' => '']);
        $table->addColumn('api_token_id', Types::GUID, ['notnull' => false]);
        $table->addColumn('actor_kind', Types::STRING, ['length' => 16, 'default' => 'user']);
        $table->setPrimaryKey(['id']);
        $table->addIndex(['correlation_id'], 'idx_audit_entry_correlation');
        $table->addIndex(['entity_class', 'entity_id'], 'idx_audit_entry_entity');
        $table->addIndex(['root_class', 'root_id'], 'idx_audit_entry_root');
    }
}
