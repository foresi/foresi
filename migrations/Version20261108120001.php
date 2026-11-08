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
 * Files kept for records of any module; the bytes live in the attachment
 * directory. A new table only: nothing existing changes.
 */
final class Version20261108120001 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add attachments';
    }

    public function up(Schema $schema): void
    {
        $attachment = $schema->createTable('attachment');
        $attachment->addColumn('id', Types::GUID);
        $attachment->addColumn('tenant_id', 'tenant_id');
        $attachment->addColumn('owner_kind', Types::STRING, ['length' => 40]);
        $attachment->addColumn('owner_id', Types::GUID);
        $attachment->addColumn('file_name', Types::STRING, ['length' => 255]);
        $attachment->addColumn('media_type', Types::STRING, ['length' => 127]);
        $attachment->addColumn('size', Types::INTEGER);
        $attachment->addColumn('sha256', Types::STRING, ['length' => 64]);
        $attachment->addColumn('storage_key', Types::STRING, ['length' => 255]);
        $attachment->addColumn('uploaded_by', Types::GUID);
        $attachment->addColumn('created_at', Types::DATETIME_IMMUTABLE);
        $attachment->addColumn('deleted_at', Types::DATETIME_IMMUTABLE, ['notnull' => false]);
        $attachment->setPrimaryKey(['id']);
        $attachment->addIndex(['tenant_id'], 'idx_attachment_tenant');
        $attachment->addIndex(['tenant_id', 'owner_kind', 'owner_id'], 'idx_attachment_owner');
    }

    public function down(Schema $schema): void
    {
        $schema->dropTable('attachment');
    }
}
