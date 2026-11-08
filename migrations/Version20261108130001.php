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
 * Comments on tasks and the people they mention. New tables only: nothing
 * existing changes.
 */
final class Version20261108130001 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add task comments and their mentions';
    }

    public function up(Schema $schema): void
    {
        $comment = $schema->createTable('task_comment');
        $comment->addColumn('id', Types::GUID);
        $comment->addColumn('tenant_id', 'tenant_id');
        $comment->addColumn('task_id', Types::GUID);
        $comment->addColumn('author_id', Types::GUID);
        $comment->addColumn('body', Types::TEXT);
        $comment->addColumn('internal', Types::BOOLEAN);
        $comment->addColumn('created_at', Types::DATETIME_IMMUTABLE);
        $comment->addColumn('edited_at', Types::DATETIME_IMMUTABLE, ['notnull' => false]);
        $comment->addColumn('deleted_at', Types::DATETIME_IMMUTABLE, ['notnull' => false]);
        $comment->setPrimaryKey(['id']);
        $comment->addIndex(['tenant_id'], 'idx_task_comment_tenant');
        $comment->addIndex(['task_id', 'created_at'], 'idx_task_comment_task');
        $comment->addIndex(['author_id'], 'idx_task_comment_author');
        $comment->addForeignKeyConstraint('task', ['task_id'], ['id'], ['onDelete' => 'RESTRICT'], 'fk_task_comment_task');
        $comment->addForeignKeyConstraint('membership', ['author_id'], ['id'], ['onDelete' => 'RESTRICT'], 'fk_task_comment_author');

        $mention = $schema->createTable('comment_mention');
        $mention->addColumn('id', Types::GUID);
        $mention->addColumn('tenant_id', 'tenant_id');
        $mention->addColumn('comment_id', Types::GUID);
        $mention->addColumn('membership_id', Types::GUID);
        $mention->setPrimaryKey(['id']);
        $mention->addIndex(['tenant_id'], 'idx_comment_mention_tenant');
        $mention->addIndex(['membership_id'], 'idx_comment_mention_membership');
        $mention->addUniqueIndex(['comment_id', 'membership_id'], 'uniq_comment_mention');
        $mention->addForeignKeyConstraint('task_comment', ['comment_id'], ['id'], ['onDelete' => 'RESTRICT'], 'fk_comment_mention_comment');
        $mention->addForeignKeyConstraint('membership', ['membership_id'], ['id'], ['onDelete' => 'RESTRICT'], 'fk_comment_mention_membership');
    }

    public function down(Schema $schema): void
    {
        $schema->dropTable('comment_mention');
        $schema->dropTable('task_comment');
    }
}
