<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Work\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Every task now knows when it last changed (Version20261125130000), so it is required.
 */
final class Version20261125130001 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Require when each task last changed';
    }

    public function up(Schema $schema): void
    {
        $schema->getTable('task')->modifyColumn('updated_at', ['notnull' => true]);
    }

    public function down(Schema $schema): void
    {
        $schema->getTable('task')->modifyColumn('updated_at', ['notnull' => false]);
    }
}
