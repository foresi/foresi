<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Sales\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * An hourly deal line may link to one project, and a project serves one line at most.
 */
final class Version20261125100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Link an hourly deal line to a project';
    }

    public function up(Schema $schema): void
    {
        $table = $schema->getTable('sales_deal_line');
        $table->addColumn('project_id', 'guid', ['notnull' => false]);
        $table->addUniqueIndex(['project_id'], 'uniq_sales_deal_line_project');
    }

    public function down(Schema $schema): void
    {
        $table = $schema->getTable('sales_deal_line');
        $table->dropIndex('uniq_sales_deal_line_project');
        $table->dropColumn('project_id');
    }
}
