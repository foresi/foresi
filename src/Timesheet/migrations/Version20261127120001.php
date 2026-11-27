<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Timesheet\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Types\Types;
use Doctrine\Migrations\AbstractMigration;

/**
 * The project no longer holds its time settings: Version20261127120000 copied
 * them to Timesheet's tables, and the columns go. A fresh install never had
 * them, so nothing changes there. Going down gives the project the columns
 * back, where Version20261127120000 going down copies the settings to.
 */
final class Version20261127120001 extends AbstractMigration
{
    private const array COLUMNS = ['billable_by_default', 'hourly_rate_minor', 'hourly_rate_currency', 'budget_counting'];

    public function getDescription(): string
    {
        return 'Take the time settings off the project';
    }

    public function up(Schema $schema): void
    {
        if (!$schema->hasTable('project')) {
            return;
        }
        $project = $schema->getTable('project');
        foreach (self::COLUMNS as $column) {
            if ($project->hasColumn($column)) {
                $project->dropColumn($column);
            }
        }
    }

    public function down(Schema $schema): void
    {
        $project = $schema->getTable('project');
        $project->addColumn('billable_by_default', Types::BOOLEAN, ['default' => false]);
        $project->addColumn('hourly_rate_minor', Types::BIGINT, ['notnull' => false]);
        $project->addColumn('hourly_rate_currency', Types::STRING, ['length' => 3, 'fixed' => true, 'notnull' => false]);
        $project->addColumn('budget_counting', Types::STRING, ['length' => 16, 'default' => 'all']);
    }
}
