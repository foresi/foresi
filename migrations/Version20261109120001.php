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
 * Sharing of work: who of the team sees a project, private sections and
 * tasks, and the people each is shared with. Every existing project stays
 * open to the organisation and nothing is private, so everyone keeps seeing
 * what they saw.
 */
final class Version20261109120001 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add sharing of projects, sections and tasks';
    }

    public function up(Schema $schema): void
    {
        $schema->getTable('project')->addColumn('visibility', Types::STRING, ['length' => 16, 'default' => 'organisation']);
        $schema->getTable('project_section')->addColumn('private', Types::BOOLEAN, ['default' => false]);
        $schema->getTable('task')->addColumn('private', Types::BOOLEAN, ['default' => false]);

        $share = $schema->createTable('work_share');
        $share->addColumn('id', Types::GUID);
        $share->addColumn('tenant_id', 'tenant_id');
        $share->addColumn('membership_id', Types::GUID);
        $share->addColumn('project_id', Types::GUID, ['notnull' => false]);
        $share->addColumn('section_id', Types::GUID, ['notnull' => false]);
        $share->addColumn('task_id', Types::GUID, ['notnull' => false]);
        $share->addColumn('created_at', Types::DATETIME_IMMUTABLE);
        $share->setPrimaryKey(['id']);
        $share->addIndex(['tenant_id'], 'idx_work_share_tenant');
        $share->addIndex(['project_id'], 'idx_work_share_project');
        $share->addIndex(['section_id'], 'idx_work_share_section');
        $share->addIndex(['task_id'], 'idx_work_share_task');
        $share->addUniqueIndex(['membership_id', 'project_id'], 'uniq_work_share_project');
        $share->addUniqueIndex(['membership_id', 'section_id'], 'uniq_work_share_section');
        $share->addUniqueIndex(['membership_id', 'task_id'], 'uniq_work_share_task');
        $share->addForeignKeyConstraint('membership', ['membership_id'], ['id'], ['onDelete' => 'RESTRICT'], 'fk_work_share_membership');
        $share->addForeignKeyConstraint('project', ['project_id'], ['id'], ['onDelete' => 'RESTRICT'], 'fk_work_share_project');
        $share->addForeignKeyConstraint('project_section', ['section_id'], ['id'], ['onDelete' => 'RESTRICT'], 'fk_work_share_section');
        $share->addForeignKeyConstraint('task', ['task_id'], ['id'], ['onDelete' => 'RESTRICT'], 'fk_work_share_task');
    }

    public function down(Schema $schema): void
    {
        $schema->dropTable('work_share');
        $schema->getTable('task')->dropColumn('private');
        $schema->getTable('project_section')->dropColumn('private');
        $schema->getTable('project')->dropColumn('visibility');
    }
}
