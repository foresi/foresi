<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Attachment\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Types\Types;
use Doctrine\Migrations\AbstractMigration;

/**
 * The baseline of Attachment: its 1 table as the global migrations left
 * them (ADR 0034). A database migrated before the module packages moves onto
 * it with app:migrations:rebase, which runs none of this.
 */
final class Version20261120000000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create the table of Attachment';
    }

    public function up(Schema $schema): void
    {
        $this->attachment($schema);
    }

    public function down(Schema $schema): void
    {
        $schema->dropTable('attachment');
    }

    private function attachment(Schema $schema): void
    {
        $table = $schema->createTable('attachment');
        $table->addColumn('id', Types::GUID);
        $table->addColumn('owner_kind', Types::STRING, ['length' => 40]);
        $table->addColumn('owner_id', Types::GUID);
        $table->addColumn('file_name', Types::STRING, ['length' => 255]);
        $table->addColumn('media_type', Types::STRING, ['length' => 127]);
        $table->addColumn('size', Types::INTEGER);
        $table->addColumn('sha256', Types::STRING, ['length' => 64]);
        $table->addColumn('storage_key', Types::STRING, ['length' => 255]);
        $table->addColumn('uploaded_by', Types::GUID);
        $table->addColumn('created_at', Types::DATETIME_IMMUTABLE);
        $table->addColumn('deleted_at', Types::DATETIME_IMMUTABLE, ['notnull' => false]);
        $table->addColumn('deleted_by', Types::GUID, ['notnull' => false]);
        $table->setPrimaryKey(['id']);
        $table->addIndex(['deleted_at'], 'idx_attachment_deleted');
        $table->addIndex(['owner_kind', 'owner_id'], 'idx_attachment_owner');
    }
}
