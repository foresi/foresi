<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Types\Types;
use Doctrine\Migrations\AbstractMigration;

final class Version20261118620001 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Keep the text of a deleted task comment until it is purged, and who deleted it';
    }

    public function up(Schema $schema): void
    {
        $comment = $schema->getTable('task_comment');
        $comment->addColumn('deleted_by', Types::GUID, ['notnull' => false]);
        $comment->addIndex(['deleted_at'], 'idx_task_comment_deleted');
    }

    public function down(Schema $schema): void
    {
        $comment = $schema->getTable('task_comment');
        $comment->dropIndex('idx_task_comment_deleted');
        $comment->dropColumn('deleted_by');
    }
}
