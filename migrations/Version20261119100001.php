<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Types\Types;
use Doctrine\Migrations\AbstractMigration;

final class Version20261119100001 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Who deleted an attachment, and an index for the purge of deleted ones';
    }

    public function up(Schema $schema): void
    {
        $attachment = $schema->getTable('attachment');
        $attachment->addColumn('deleted_by', Types::GUID, ['notnull' => false]);
        $attachment->addIndex(['deleted_at'], 'idx_attachment_deleted');
    }

    public function down(Schema $schema): void
    {
        $attachment = $schema->getTable('attachment');
        $attachment->dropIndex('idx_attachment_deleted');
        $attachment->dropColumn('deleted_by');
    }
}
